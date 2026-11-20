<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: MetForm.
 *
 * One hub. Reads cover the forms, their settings, their notifications, their
 * fields and their entries; writes cover the handful of things an owner actually
 * asks for — make a form, rename it, change the success message, change where a
 * submission redirects, switch the two email notifications on or off and set
 * their addresses and bodies — plus three deletions that are named for what they
 * do.
 *
 * Verified against MetForm 4.3.1 (metform.php:8).
 *
 * WHERE THE FIELDS LIVE, and why they are READ-ONLY here.
 * MetForm is a form builder for Elementor, and a form's fields are not a tidy
 * meta array: they are Elementor widgets inside the `_elementor_data` JSON on the
 * `metform-form` post. MetForm reads them back out of exactly that meta and walks
 * the widget tree looking for its own widget types
 * (core/entries/action.php:1323, core/entries/map-el.php:62), and a field's
 * "name" is the `mf_input_name` setting on the widget (core/entries/map-el.php:64).
 * So a field is one node in a nested Elementor document whose siblings carry
 * layout, responsive settings, per-breakpoint styles and element IDs that
 * Elementor's own revision and CSS caches are keyed on.
 *
 * Writing one field back means re-serialising that whole document. Get a node ID
 * wrong, drop a key Elementor did not expect to lose, or write the JSON unslashed
 * and the form stops rendering — and the owner's only clue is a blank page where
 * their form used to be. Elementor also writes its own slashed-JSON encoding on
 * save (core/forms/builder.php:188), which is not something to reimplement from
 * the outside. So every field operation here reads. An honest read-only surface
 * beats a write that corrupts a live form, and the answer when asked to add a
 * field is: open the form in Elementor. get_form_fields says so in its own
 * response.
 *
 * The same reasoning covers required fields: `mf_input_required` is a setting on
 * the widget (traits/common-controls.php:149), so it is reported and not written.
 *
 * ENTRIES ARE PERSONAL DATA. An entry is a `metform-entry` post whose submitted
 * values sit in the `metform_entries__form_data` meta, linked to its form by
 * `metform_entries__form_id` (core/entries/action.php:53-55) — there is no custom
 * table. Reading them takes manage_options, which is what MetForm itself requires
 * on every entries REST route (core/entries/api.php:231) and on its export
 * (core/entries/export.php:15). The `metform-entry` CPT screen is reachable on
 * the weaker `edit_pages` — it registers capability_type 'page'
 * (core/entries/cpt.php:64) — and this takes the stricter of the two on purpose.
 * Every listing is bounded and no bound can be raised from input.
 *
 * CREDENTIALS. MetForm keeps integration credentials in the same flat settings
 * array as the success message: the reCAPTCHA v2 and v3 secret keys, the
 * Mailchimp / MailerLite / ConvertKit / GetResponse / ActiveCampaign / AWeber API
 * keys, the Stripe and PayPal keys, the Twilio auth token, the HubSpot, Zoho and
 * Help Scout tokens, and the Zapier, Slack, Fluent and REST webhook URLs — which
 * ARE the credential, since whoever holds the URL can post to it
 * (core/forms/cpt.php:140-537). Settings leave here through an ALLOW-LIST of keys
 * whose value may travel; everything else is reported as a name and whether it is
 * set. An allow-list rather than a denylist because that array grows with every
 * release and with every Pro add-on, so a key nobody here has heard of must
 * default to withheld rather than to leaking.
 *
 * (MetForm has no Turnstile: its captcha options are Google reCAPTCHA v2/v3
 * (core/integrations/google-recaptcha.php:13) and a self-hosted simple captcha
 * (widgets/simple-captcha/simple-captcha.php). There is no Turnstile secret to
 * withhold, so none is claimed.)
 */

/** MetForm's form post type (core/forms/cpt.php:13). */
const NIBWP_METFORM_FORM_TYPE = 'metform-form';

/** MetForm's entry post type (core/entries/cpt.php:8). */
const NIBWP_METFORM_ENTRY_TYPE = 'metform-entry';

/** Per-form settings live in one serialised meta array (core/forms/cpt.php:18). */
const NIBWP_METFORM_SETTINGS_META = 'metform_form__form_setting';

/** Site-wide settings, merged over the per-form ones (core/admin/base.php:20). */
const NIBWP_METFORM_GLOBAL_OPTION = 'metform_option__settings';

/** Which form an entry belongs to (core/entries/action.php:53). */
const NIBWP_METFORM_ENTRY_FORM_META = 'metform_entries__form_id';

/** The submitted values (core/entries/action.php:54). */
const NIBWP_METFORM_ENTRY_DATA_META = 'metform_entries__form_data';

/** Uploaded files on an entry (core/entries/action.php:55). */
const NIBWP_METFORM_ENTRY_FILE_META = 'metform_entries__file_upload_new';

/** The Elementor document that holds the fields (core/entries/action.php:1323). */
const NIBWP_METFORM_ELEMENTOR_META = '_elementor_data';

/**
 * Reading or deleting an entry takes this.
 *
 * MetForm gates every entries REST route and its CSV export on manage_options
 * (core/entries/api.php:231, core/entries/export.php:15). Entries carry whatever
 * a visitor typed into the form, so this is the floor, not a starting point.
 */
const NIBWP_METFORM_ENTRIES_CAP = 'manage_options';

/** How many rows any listing returns, whatever was asked for. */
const NIBWP_METFORM_MAX_ROWS = 100;

/** How many forms an audit will walk in one pass. */
const NIBWP_METFORM_MAX_FORMS = 200;

/** Longest string value this hub will echo back out of a form or an entry. */
const NIBWP_METFORM_MAX_VALUE_CHARS = 2000;

/**
 * Is MetForm actually loaded?
 *
 * The constant is defined by the main plugin file before anything else
 * (metform.php:18) and the Plugin class is what boots the rest, so between them
 * they separate "not installed" from "installed and half-loaded".
 */
function nibwp_metform_guard(): ?WP_Error
{
    if (!defined('METFORM_VERSION') && !class_exists('MetForm\\Plugin')) {
        return new WP_Error(
            'nibwp_metform_missing',
            __('MetForm is not active on this site.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/** A refusal about the request itself. */
function nibwp_metform_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error('nibwp_metform_' . $code, $message, ['status' => $status]);
}

/** A refusal about authority rather than about the request. */
function nibwp_metform_forbidden(string $what, string $cap): WP_Error
{
    return new WP_Error(
        'nibwp_metform_forbidden',
        sprintf(
            /* translators: 1: what was being attempted, 2: capability name */
            __('%1$s needs the %2$s capability, which this account does not have.', 'nibwp'),
            $what,
            $cap
        ),
        ['status' => 403]
    );
}

/** The capability gate for everything that touches an entry. */
function nibwp_metform_require_entries(string $what): ?WP_Error
{
    return current_user_can(NIBWP_METFORM_ENTRIES_CAP)
        ? null
        : nibwp_metform_forbidden($what, NIBWP_METFORM_ENTRIES_CAP);
}

/**
 * The capability gate for reading the form list and the form settings.
 *
 * The forms CPT registers capability_type 'page' (core/forms/cpt.php:601), so the
 * primitive capability WordPress derives for its screens is edit_pages. It is
 * read from the registered post type rather than hard-coded, because that is
 * where the answer lives and a later MetForm release could change it.
 */
function nibwp_metform_forms_cap(): string
{
    if (function_exists('get_post_type_object')) {
        $type = get_post_type_object(NIBWP_METFORM_FORM_TYPE);

        if (is_object($type) && isset($type->cap) && is_object($type->cap) && isset($type->cap->edit_posts)) {
            $cap = (string) $type->cap->edit_posts;

            if ($cap !== '') {
                return $cap;
            }
        }
    }

    return 'edit_pages';
}

/** Reading the forms surface. */
function nibwp_metform_require_forms(string $what): ?WP_Error
{
    $cap = nibwp_metform_forms_cap();

    return current_user_can($cap) ? null : nibwp_metform_forbidden($what, $cap);
}

/**
 * May this account change THIS form?
 *
 * The per-object question comes first, which is the right question. But it cannot
 * be the only one on this post type: metform-form registers capability_type
 * 'page' and does NOT set map_meta_cap (core/forms/cpt.php:601), so WordPress
 * resolves edit_post on it to the literal capability 'edit_page'
 * (wp-includes/capabilities.php:242-247) — which no role holds, not even an
 * administrator's. Asking only the per-object question on this type refuses
 * everybody, so there is a second question, and the one MetForm itself asks
 * before it writes a form is manage_options (core/forms/api.php:21). Note the
 * fallback is STRICTER than the screen capability, not weaker: an editor with
 * edit_pages does not get in this way.
 *
 * @param string $meta_cap 'edit_post' or 'delete_post'.
 */
function nibwp_metform_can_touch_form(int $form_id, string $what, string $meta_cap = 'edit_post'): ?WP_Error
{
    if (current_user_can($meta_cap, $form_id)) {
        return null;
    }

    if (current_user_can('manage_options')) {
        return null;
    }

    return nibwp_metform_forbidden($what, $meta_cap . ' / manage_options');
}

/**
 * Resolve a form id, checking ONLY that it is a MetForm form.
 *
 * Deliberately alone and deliberately first. An id from an agent is a claim, and
 * if the capability check ran first then "yes you may edit post 12" would be
 * answered about a page, and the next line would be writing MetForm settings onto
 * it — or trashing it. So the type is established before authority is discussed,
 * and the two refusals carry different codes so a caller (and the check) can tell
 * which one spoke.
 *
 * Declared `object` rather than `WP_Post|WP_Error`: a union with WP_Error in it is
 * a parse error on this runtime, and WP_Error is an object, so the caller tests
 * with instanceof.
 */
function nibwp_metform_form(mixed $raw): object
{
    $id = is_numeric($raw) ? (int) $raw : 0;

    if ($id <= 0) {
        return nibwp_metform_err('missing_form_id', __('Give the form as form_id, a positive post ID.', 'nibwp'), 422);
    }

    $post = get_post($id);

    if (!is_object($post) || !isset($post->post_type)) {
        return nibwp_metform_err(
            'form_not_found',
            sprintf(
                /* translators: %d: the post ID that was asked for */
                __('There is no post with ID %d.', 'nibwp'),
                $id
            ),
            404
        );
    }

    if ((string) $post->post_type !== NIBWP_METFORM_FORM_TYPE) {
        return nibwp_metform_err(
            'not_a_form',
            sprintf(
                /* translators: 1: post ID, 2: the post type it actually is, 3: MetForm's form post type */
                __('Post %1$d is a "%2$s", not a "%3$s". Nothing was read or changed.', 'nibwp'),
                $id,
                (string) $post->post_type,
                NIBWP_METFORM_FORM_TYPE
            ),
            422
        );
    }

    return $post;
}

/**
 * Resolve an entry id, checking ONLY that it is a MetForm entry.
 *
 * Same shape and same reason as nibwp_metform_form(): the type first, alone.
 */
function nibwp_metform_entry(mixed $raw): object
{
    $id = is_numeric($raw) ? (int) $raw : 0;

    if ($id <= 0) {
        return nibwp_metform_err('missing_entry_id', __('Give the entry as entry_id, a positive post ID.', 'nibwp'), 422);
    }

    $post = get_post($id);

    if (!is_object($post) || !isset($post->post_type)) {
        return nibwp_metform_err(
            'entry_not_found',
            sprintf(
                /* translators: %d: the post ID that was asked for */
                __('There is no post with ID %d.', 'nibwp'),
                $id
            ),
            404
        );
    }

    if ((string) $post->post_type !== NIBWP_METFORM_ENTRY_TYPE) {
        return nibwp_metform_err(
            'not_an_entry',
            sprintf(
                /* translators: 1: post ID, 2: the post type it actually is, 3: MetForm's entry post type */
                __('Post %1$d is a "%2$s", not a "%3$s". Nothing was read or deleted.', 'nibwp'),
                $id,
                (string) $post->post_type,
                NIBWP_METFORM_ENTRY_TYPE
            ),
            422
        );
    }

    return $post;
}

/** Clamp a row count that arrived over the wire. */
function nibwp_metform_limit(mixed $value, int $default = 25): int
{
    $limit = is_numeric($value) ? (int) $value : $default;

    return max(1, min(NIBWP_METFORM_MAX_ROWS, $limit));
}

/** Clamp an offset that arrived over the wire. */
function nibwp_metform_offset(mixed $value): int
{
    return is_numeric($value) ? max(0, (int) $value) : 0;
}

/** Clamp a window in days. */
function nibwp_metform_days(mixed $value, int $default = 30): int
{
    $days = is_numeric($value) ? (int) $value : $default;

    return max(1, min(365, $days));
}

/**
 * Settings keys whose VALUE may be reported, and what each one is.
 *
 * Everything else in the settings array is reported as a name and whether it is
 * set. These are the keys that answer "what does this form do when somebody
 * submits it", which is the question an owner is actually asking.
 *
 * @return array<string, string> key => bool|int|string
 */
function nibwp_metform_public_settings(): array
{
    return [
        'form_title'                          => 'string',
        'form_type'                           => 'string',
        'success_message'                     => 'string',
        'store_entries'                       => 'bool',
        'entry_title'                         => 'string',
        'hide_form_after_submission'          => 'bool',
        'redirect_to'                         => 'string',
        'require_login'                       => 'bool',
        'multiple_submission'                 => 'bool',
        'limit_total_entries_status'           => 'bool',
        'limit_total_entries'                 => 'int',
        'form_scheduling_status'              => 'bool',
        'mf_scheduling_submission_starts'     => 'string',
        'mf_scheduling_submission_ends'       => 'string',
        'mf_scheduling_form_waiting_message'  => 'string',
        'mf_scheduling_form_expired_message'  => 'string',
        'count_views'                         => 'bool',
        'capture_user_browser_data'           => 'bool',
        'enable_recaptcha'                    => 'bool',
        'mf_recaptcha'                        => 'bool',
        'mf_recaptcha_version'                => 'string',
        'enable_user_notification'            => 'bool',
        'user_email_subject'                  => 'string',
        'user_email_from'                     => 'string',
        'user_email_reply_to'                 => 'string',
        'user_email_body'                     => 'string',
        'user_email_attach_submission_copy'   => 'bool',
        'enable_admin_notification'           => 'bool',
        'admin_email_subject'                 => 'string',
        'admin_email_from'                    => 'string',
        'admin_email_to'                      => 'string',
        'admin_email_reply_to'                => 'string',
        'admin_email_body'                    => 'string',
        'admin_email_attach_submission_copy'  => 'bool',
        'email_verification_enable'           => 'bool',
        'mf_field_name_show'                  => 'bool',
        'mf_save_progress'                    => 'bool',
        'mf_enable_entry_file_delete'         => 'bool',
        'mf_enable_form_analytics'            => 'bool',
        'mf_enable_submission_error_log'      => 'bool',
        'mf_payment_currency'                 => 'string',
        'mf_paypal_sandbox'                   => 'bool',
        'mf_stripe_sandbox'                   => 'bool',
    ];
}

/**
 * Settings keys whose value must never travel, however it is asked for.
 *
 * This is the second layer, not the only one: the allow-list above already
 * governs what leaves, and a key in neither list is withheld by default. These
 * are named so that get_settings can report "the Mailchimp key IS configured"
 * without handing it over, and so that a key landing in both lists fails the
 * check loudly rather than quietly leaking.
 *
 * The webhook URLs belong here and are not an oversight: a Zapier or Slack
 * webhook URL is a bearer credential — anyone holding it can post into the
 * owner's workspace.
 *
 * @return array<int, string>
 */
function nibwp_metform_secret_settings(): array
{
    return [
        'mf_recaptcha_secret_key',
        'mf_recaptcha_secret_key_v3',
        'mf_recaptcha_site_key',
        'mf_recaptcha_site_key_v3',
        'mf_mailchimp_api_key',
        'mf_get_reponse_api_key',
        'mf_automizy_api_token',
        'mf_active_campaign_api_key',
        'mf_ckit_api_key',
        'mf_ckit_sec_key',
        'mf_mailerlite_api_key',
        'mf_aweber_dev_api_key',
        'mf_aweber_dev_api_sec',
        'mf_hubsopt_token',
        'mf_hubsopt_refresh_token',
        'mf_zoho_token',
        'mf_helpscout_app_id',
        'mf_helpscout_app_secret',
        'mf_helpscout_token',
        'mf_google_sheet_client_id',
        'mf_google_sheet_client_secret',
        'mf_google_map_api_key',
        'mf_dropbox_app_id',
        'mf_dropbox_app_secret',
        'mf_stripe_live_publishiable_key',
        'mf_stripe_live_secret_key',
        'mf_stripe_test_publishiable_key',
        'mf_stripe_test_secret_key',
        'mf_paypal_email',
        'mf_paypal_token',
        'mf_sms_twilio_account_sid',
        'mf_sms_twilio_auth_token',
        'mf_zapier_webhook',
        'mf_slack_webhook',
        'mf_fluent_webhook',
        'mf_rest_api_url',
    ];
}

/**
 * Settings keys this hub will ever write.
 *
 * A third layer, and the one that matters most: nothing reaches update_post_meta
 * that is not named here, so no write path can be talked into setting a
 * credential, a payment key or a webhook, whatever the caller sends.
 *
 * @return array<int, string>
 */
function nibwp_metform_writable_settings(): array
{
    return [
        'form_title',
        'success_message',
        'redirect_to',
        'enable_admin_notification',
        'admin_email_to',
        'admin_email_subject',
        'admin_email_body',
        'enable_user_notification',
        'user_email_subject',
        'user_email_body',
    ];
}

/**
 * Strip anything that looks like a credential out of a structure, at any depth.
 *
 * The allow-list is what governs the settings reports, so this is belt and
 * braces — but it earns its place on the paths where the shape is not ours:
 * an entry's submitted values are keyed by whatever the form's inputs are called,
 * and an Elementor widget's settings are an open-ended blob. A secret nested
 * three levels inside one of those would otherwise ride out under an innocent
 * key.
 *
 * @param mixed $value
 * @return mixed
 */
function nibwp_metform_scrub(mixed $value, int $depth = 0): mixed
{
    if ($depth > 8) {
        return null;
    }

    if (is_object($value)) {
        $value = get_object_vars($value);
    }

    if (!is_array($value)) {
        return nibwp_metform_clip($value);
    }

    $secrets = nibwp_metform_secret_settings();
    $clean = [];

    foreach ($value as $key => $item) {
        if (is_string($key) && in_array($key, $secrets, true)) {
            $clean[$key] = '[withheld]';
            continue;
        }

        $clean[$key] = nibwp_metform_scrub($item, $depth + 1);
    }

    return $clean;
}

/**
 * Keep one scalar to a sane size, and refuse to stringify anything else.
 *
 * @param mixed $value
 * @return mixed
 */
function nibwp_metform_clip(mixed $value): mixed
{
    if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
        return $value;
    }

    if (!is_scalar($value)) {
        return null;
    }

    $text = (string) $value;

    return strlen($text) > NIBWP_METFORM_MAX_VALUE_CHARS
        ? substr($text, 0, NIBWP_METFORM_MAX_VALUE_CHARS) . '…'
        : $text;
}

/** Report one setting the way this integration reports settings. */
function nibwp_metform_setting_value(mixed $value, string $type): mixed
{
    return match ($type) {
        'bool' => (bool) $value && $value !== '0',
        'int' => is_numeric($value) ? (int) $value : 0,
        default => is_scalar($value) ? nibwp_metform_clip($value) : null,
    };
}

/**
 * The settings array for one form.
 *
 * @return array<string, mixed>
 */
function nibwp_metform_settings(int $form_id): array
{
    $settings = get_post_meta($form_id, NIBWP_METFORM_SETTINGS_META, true);

    return is_array($settings) ? $settings : [];
}

/**
 * The site-wide settings array.
 *
 * MetForm merges these OVER the per-form ones when it serves a form
 * (core/forms/action.php:219), which is why a reCAPTCHA key set globally applies
 * to a form that never mentions one.
 *
 * @return array<string, mixed>
 */
function nibwp_metform_global_settings(): array
{
    $settings = get_option(NIBWP_METFORM_GLOBAL_OPTION, []);

    return is_array($settings) ? $settings : [];
}

/**
 * Turn a settings array into a response: values for the allow-list, names for
 * everything else.
 *
 * @param array<string, mixed> $settings
 * @return array<string, mixed>
 */
function nibwp_metform_report_settings(array $settings): array
{
    $public = [];

    foreach (nibwp_metform_public_settings() as $key => $type) {
        if (!array_key_exists($key, $settings)) {
            continue;
        }

        // A non-scalar under an allow-listed key is dropped rather than cast: a
        // blob is how a credential would travel under a name that looks safe.
        $public[$key] = nibwp_metform_setting_value($settings[$key], $type);
    }

    $withheld = [];
    $allowed = nibwp_metform_public_settings();

    foreach ($settings as $key => $value) {
        if (!is_string($key) || isset($allowed[$key])) {
            continue;
        }

        $withheld[] = [
            'key' => $key,
            'is_set' => $value !== '' && $value !== null && $value !== false && $value !== [],
            'value_returned' => false,
        ];
    }

    return [
        'settings' => $public,
        'withheld' => $withheld,
        'note' => __('Only the keys above report a value. Everything else in MetForm\'s settings — every API key, payment key, token and webhook URL — is reported as a name and whether it is set, and asking differently will not change that.', 'nibwp'),
    ];
}

/**
 * The attributes of a field that may be reported.
 *
 * An Elementor widget's settings object carries the field's own configuration
 * next to its typography, spacing, per-breakpoint overrides and whatever an
 * add-on put there. This is the handful that describes the field as a field.
 *
 * @return array<int, string>
 */
function nibwp_metform_field_attributes(): array
{
    return [
        'mf_input_label',
        'mf_input_name',
        'mf_input_placeholder',
        'mf_input_required',
        'mf_input_min_length',
        'mf_input_max_length',
        'mf_input_length_type',
        'mf_input_validation_expression',
        'mf_input_help_text',
    ];
}

/**
 * Read one form's fields out of its Elementor document.
 *
 * MetForm's own reader is \MetForm\Core\Entries\Action::get_fields(), which pulls
 * the same meta and walks it with Map_El (core/entries/action.php:1315-1326). It
 * is not used here for two reasons: it needs Elementor's widget manifest to be
 * loaded, which it is not during a REST request that never touched the editor,
 * and it rewrites `mf_input_name` on the objects it returns
 * (core/entries/map-el.php:67). Walking the JSON ourselves is read-only in the
 * strictest sense — nothing is written back, not even in memory that MetForm
 * owns. The test for "is this one of MetForm's widgets" is the same one MetForm
 * makes: elType is 'widget' and the widget type is one of its own
 * (core/entries/map-el.php:62).
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_metform_fields(int $form_id): array
{
    $raw = get_post_meta($form_id, NIBWP_METFORM_ELEMENTOR_META, true);

    if (!is_string($raw) || $raw === '') {
        return [];
    }

    $tree = json_decode($raw, true);

    if (!is_array($tree)) {
        return [];
    }

    $fields = [];
    nibwp_metform_walk_elements($tree, $fields, 0);

    return $fields;
}

/**
 * Walk an Elementor element tree collecting MetForm input widgets.
 *
 * @param array<int|string, mixed> $elements
 * @param array<int, array<string, mixed>> $fields
 */
function nibwp_metform_walk_elements(array $elements, array &$fields, int $depth): void
{
    // Elementor documents nest section → column → widget, and a container tree
    // can nest further. A bound stops a hand-edited or looping document from
    // taking the request down with it.
    if ($depth > 24 || count($fields) >= NIBWP_METFORM_MAX_ROWS) {
        return;
    }

    foreach ($elements as $element) {
        if (!is_array($element)) {
            continue;
        }

        $children = isset($element['elements']) && is_array($element['elements']) ? $element['elements'] : [];

        if ($children !== []) {
            nibwp_metform_walk_elements($children, $fields, $depth + 1);
            continue;
        }

        $el_type = isset($element['elType']) && is_scalar($element['elType']) ? (string) $element['elType'] : '';
        $widget = isset($element['widgetType']) && is_scalar($element['widgetType']) ? (string) $element['widgetType'] : '';

        if ($el_type !== 'widget' || !str_starts_with($widget, 'mf-')) {
            continue;
        }

        $settings = isset($element['settings']) && is_array($element['settings']) ? $element['settings'] : [];
        $row = [
            'widget_type' => str_replace('[]', '', $widget),
            'element_id' => isset($element['id']) && is_scalar($element['id']) ? (string) $element['id'] : null,
        ];

        foreach (nibwp_metform_field_attributes() as $attribute) {
            if (array_key_exists($attribute, $settings)) {
                $row[$attribute] = nibwp_metform_clip($settings[$attribute]);
            }
        }

        $row['required'] = isset($settings['mf_input_required']) && $settings['mf_input_required'] === 'yes';

        $fields[] = $row;

        if (count($fields) >= NIBWP_METFORM_MAX_ROWS) {
            return;
        }
    }
}

/**
 * How many entries a form has, optionally within a window.
 *
 * Mirrors MetForm's own count, including the statuses it excludes
 * (core/entries/action.php:1714-1724): an auto-draft is a submission that never
 * finished and a trashed entry is one somebody already deleted, so counting
 * either would overstate what the form received.
 */
function nibwp_metform_entry_count(int $form_id, ?int $days = null): int
{
    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
        return 0;
    }

    if ($days === null) {
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(p.ID) FROM ' . $wpdb->posts . ' p'
            . ' INNER JOIN ' . $wpdb->postmeta . ' m ON m.post_id = p.ID'
            . ' WHERE m.meta_key = %s AND m.meta_value = %d'
            . ' AND p.post_type = %s'
            . ' AND p.post_status NOT IN (\'auto-draft\', \'trash\')',
            NIBWP_METFORM_ENTRY_FORM_META,
            $form_id,
            NIBWP_METFORM_ENTRY_TYPE
        ));
    }

    return (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(p.ID) FROM ' . $wpdb->posts . ' p'
        . ' INNER JOIN ' . $wpdb->postmeta . ' m ON m.post_id = p.ID'
        . ' WHERE m.meta_key = %s AND m.meta_value = %d'
        . ' AND p.post_type = %s'
        . ' AND p.post_status NOT IN (\'auto-draft\', \'trash\')'
        . ' AND p.post_date_gmt >= %s',
        NIBWP_METFORM_ENTRY_FORM_META,
        $form_id,
        NIBWP_METFORM_ENTRY_TYPE,
        gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS))
    ));
}

/**
 * Write a few named settings keys onto a form, leaving the rest alone.
 *
 * The settings array is one serialised meta value, so a write is read-modify-
 * write and a careless one drops every key it did not know about — including the
 * owner's payment keys. Only keys on the writable list are applied, and
 * everything already in the array survives untouched.
 *
 * @param array<string, mixed> $changes
 * @return array<int, string>|WP_Error the keys actually written
 */
function nibwp_metform_write_settings(int $form_id, array $changes): array|WP_Error
{
    $writable = nibwp_metform_writable_settings();
    $settings = nibwp_metform_settings($form_id);
    $written = [];

    foreach ($changes as $key => $value) {
        if (!in_array($key, $writable, true)) {
            return nibwp_metform_err(
                'not_writable',
                sprintf(
                    /* translators: %s: the settings key that was refused */
                    __('"%s" is not a setting this integration writes.', 'nibwp'),
                    (string) $key
                ),
                422
            );
        }

        $settings[$key] = $value;
        $written[] = (string) $key;
    }

    if ($written === []) {
        return nibwp_metform_err('nothing_to_write', __('No setting was given to change.', 'nibwp'), 422);
    }

    update_post_meta($form_id, NIBWP_METFORM_SETTINGS_META, $settings);

    return $written;
}

/**
 * The forms on this site, bounded.
 *
 * @return array<int, object>
 */
function nibwp_metform_form_posts(int $limit, int $offset = 0): array
{
    $posts = get_posts([
        'post_type' => NIBWP_METFORM_FORM_TYPE,
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
 * Does this form tell anybody when a submission arrives?
 *
 * MetForm treats the notification flags as checkboxes whose value is 1
 * (core/entries/action.php:788-794), so that is the test — not "is the key
 * present", which is true for every key on a form created from a template.
 *
 * @param array<string, mixed> $settings
 */
function nibwp_metform_notifies(array $settings, string $which): bool
{
    $key = $which === 'admin' ? 'enable_admin_notification' : 'enable_user_notification';

    return isset($settings[$key]) && (string) $settings[$key] === '1';
}

/**
 * Does this form keep what it receives?
 *
 * @param array<string, mixed> $settings
 */
function nibwp_metform_stores(array $settings): bool
{
    return isset($settings['store_entries']) && (string) $settings['store_entries'] === '1';
}

/**
 * The actions this hub offers.
 *
 * @return array<int, string>
 */
function nibwp_metform_actions(): array
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
        'get_notifications',
        'get_global_settings',
        // Entries — reads.
        'list_entries',
        'get_entry',
        'count_entries',
        // Audit.
        'audit_forms_without_notification',
        'audit_forms_without_entries',
        // Forms — writes.
        'create_form',
        'rename_form',
        'trash_form',
        'delete_form_permanently',
        // Settings — writes.
        'set_success_message',
        'set_redirect',
        'set_admin_notification',
        'set_user_notification',
        // Entries — writes.
        'delete_entry_permanently',
    ];
}

wp_register_ability('nibwp/metform-manage', [
    'label' => __('MetForm – Forms, Settings and Entries', 'nibwp'),
    'description' => 'Read and manage MetForm: list the forms, read one with its fields, read and change what happens after a submission (the success message, the redirect, the visitor and admin email notifications), '
        . 'read entries and count them over a period, and audit for forms that tell nobody when a submission arrives or that have never received one. '
        . 'Creates, renames, trashes and permanently deletes forms, and permanently deletes a single entry. '
        . 'Fields are READ-ONLY: MetForm builds a form as an Elementor document, so adding or changing a field means rewriting that document, and getting it wrong leaves a blank page where the form was — use Elementor for that. '
        . 'Entries are personal data and take manage_options. No API key, payment key, token or webhook URL is ever returned.',
    'category' => 'forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => nibwp_metform_actions(),
                'description' => 'Which operation to run. Start with forms_overview.',
            ],
            'form_id' => ['type' => 'integer', 'description' => 'The form post ID. Required by everything that names one form.'],
            'entry_id' => ['type' => 'integer', 'description' => 'For get_entry / delete_entry_permanently: the entry post ID.'],
            'title' => ['type' => 'string', 'description' => 'For create_form / rename_form: the form title.'],
            'message' => ['type' => 'string', 'description' => 'For set_success_message: what the visitor sees after submitting.'],
            'url' => ['type' => 'string', 'description' => 'For set_redirect: where to send the visitor after submitting. Empty clears it.'],
            'enabled' => ['type' => 'boolean', 'description' => 'For set_admin_notification / set_user_notification: on or off.'],
            'email_to' => ['type' => 'string', 'description' => 'For set_admin_notification: the recipient, or several separated by commas.'],
            'subject' => ['type' => 'string', 'description' => 'For the notification actions: the email subject.'],
            'body' => ['type' => 'string', 'description' => 'For the notification actions: the email body. MetForm shortcodes like [mf-email] are kept.'],
            'days' => ['type' => 'integer', 'description' => 'For count_entries: how many days back. Default 30, max 365.'],
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
    'execute_callback' => 'nibwp_metform_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'MetForm — forms, what they do on submit, and their entries.',
                '',
                'START WITH forms_overview. It gives every form with its entry count, whether',
                'it stores entries, and whether anybody is notified — which is usually the',
                'answer to "why did nobody see my enquiry".',
                '',
                'FIELDS ARE READ-ONLY, and this is not an omission to work around. MetForm',
                'builds a form as an Elementor document: the fields are Elementor widgets in',
                'the form post\'s _elementor_data, surrounded by layout and style settings that',
                'Elementor\'s caches are keyed on. Rewriting that from outside the editor is how',
                'a form becomes a blank space on a live page. get_form_fields and',
                'get_required_fields report what is there; adding, renaming or removing a field',
                'is done by opening the form in Elementor, and saying so is the correct answer.',
                '',
                'WHAT CAN BE CHANGED: the title, the success message, the redirect URL, and the',
                'two email notifications — set_admin_notification for the one that goes to the',
                'owner, set_user_notification for the visitor\'s confirmation. Nothing else. No',
                'action here sets an API key, a payment key, a token or a webhook — there is no',
                'such action, and the writer refuses any key that is not on its short list.',
                '',
                'ENTRIES ARE PEOPLE\'S DATA. They take manage_options, which is what MetForm',
                'itself requires for its entries screens. Prefer count_entries, which answers',
                '"is this form working" without reading anybody\'s submission; list_entries gives',
                'titles and dates only, and get_entry is the one action that returns what a',
                'person actually typed — read one when the question needs it, and quote only',
                'what it needs. Every listing is bounded at 100 rows and the bound cannot be',
                'raised.',
                '',
                'THE THREE DELETIONS: trash_form is reversible. delete_form_permanently and',
                'delete_entry_permanently are not, they take the entry\'s submitted data with',
                'them, and they are named that way so nothing calls one by accident. Confirm',
                'with the person first, and say what will be lost.',
                '',
                'CREDENTIALS: get_form_settings and get_global_settings report values only for',
                'a named list of behaviour settings. Every other key comes back as a name and',
                'whether it is set. The reCAPTCHA secret, the mail-service API keys, the Stripe',
                'and PayPal keys, the Twilio token and the Zapier/Slack/REST webhook URLs never',
                'travel, at any nesting depth, however the request is phrased.',
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
function nibwp_metform_manage(array $input): array|WP_Error
{
    $guard = nibwp_metform_guard();
    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $action = isset($input['action']) ? (string) $input['action'] : '';

    if (!in_array($action, nibwp_metform_actions(), true)) {
        return nibwp_metform_err(
            'unknown_action',
            sprintf(
                /* translators: 1: action name, 2: list of valid actions */
                __('Unknown MetForm action "%1$s". Valid actions: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_metform_actions())
            )
        );
    }

    // MetForm's own classes reach Elementor, the template library and a dozen
    // optional integrations, any of which can raise on a half-updated site. One
    // catch here turns that into a named refusal instead of a fatal that takes
    // the whole REST request with it.
    try {
        return nibwp_metform_dispatch($action, $input);
    } catch (\Throwable $error) {
        return nibwp_metform_err(
            'plugin_error',
            sprintf(
                /* translators: 1: action name, 2: error message from MetForm */
                __('MetForm could not complete %1$s: %2$s', 'nibwp'),
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
function nibwp_metform_dispatch(string $action, array $input): array|WP_Error
{
    $result = match ($action) {
        'forms_overview' => nibwp_metform_forms_overview($input),
        'list_forms' => nibwp_metform_list_forms($input),
        'get_form' => nibwp_metform_get_form($input),
        'get_form_fields' => nibwp_metform_get_form_fields($input),
        'get_required_fields' => nibwp_metform_get_required_fields($input),
        'get_form_settings' => nibwp_metform_get_form_settings($input),
        'get_notifications' => nibwp_metform_get_notifications($input),
        'get_global_settings' => nibwp_metform_get_global_settings(),
        'list_entries' => nibwp_metform_list_entries($input),
        'get_entry' => nibwp_metform_get_entry($input),
        'count_entries' => nibwp_metform_count_entries($input),
        'audit_forms_without_notification' => nibwp_metform_audit_notification($input),
        'audit_forms_without_entries' => nibwp_metform_audit_entries($input),
        'create_form' => nibwp_metform_create_form($input),
        'rename_form' => nibwp_metform_rename_form($input),
        'trash_form' => nibwp_metform_trash_form($input),
        'delete_form_permanently' => nibwp_metform_delete_form($input),
        'set_success_message' => nibwp_metform_set_success_message($input),
        'set_redirect' => nibwp_metform_set_redirect($input),
        'set_admin_notification' => nibwp_metform_set_admin_notification($input),
        'set_user_notification' => nibwp_metform_set_user_notification($input),
        'delete_entry_permanently' => nibwp_metform_delete_entry($input),
        default => nibwp_metform_err('unhandled_action', __('Unhandled action.', 'nibwp')),
    };

    return $result instanceof WP_Error ? $result : ['action' => $action] + $result;
}

/* -------------------------------------------------------------------- reads */

/**
 * Every form with the three facts that decide whether it is working.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_metform_forms_overview(array $input): array|WP_Error
{
    if ($denied = nibwp_metform_require_forms(__('Reading the MetForm forms', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_metform_limit($input['limit'] ?? null);
    $forms = nibwp_metform_form_posts($limit, nibwp_metform_offset($input['offset'] ?? null));
    $rows = [];
    $act_on = [];

    // The entry counts need manage_options, so a reader who may see the forms but
    // not the entries still gets the list — with the counts left as null and said
    // so, rather than silently reported as zero.
    $may_count = current_user_can(NIBWP_METFORM_ENTRIES_CAP);

    foreach ($forms as $form) {
        if (!is_object($form) || !isset($form->ID)) {
            continue;
        }

        $settings = nibwp_metform_settings((int) $form->ID);
        $admin = nibwp_metform_notifies($settings, 'admin');
        $user = nibwp_metform_notifies($settings, 'user');
        $stores = nibwp_metform_stores($settings);
        $count = $may_count ? nibwp_metform_entry_count((int) $form->ID) : null;

        $rows[] = [
            'id' => (int) $form->ID,
            'title' => isset($form->post_title) ? (string) $form->post_title : '',
            'status' => isset($form->post_status) ? (string) $form->post_status : '',
            'stores_entries' => $stores,
            'notifies_admin' => $admin,
            'notifies_visitor' => $user,
            'entries' => $count,
        ];

        if (!$stores && !$admin && !$user) {
            $act_on[] = sprintf(
                /* translators: 1: form title, 2: form ID */
                __('"%1$s" (#%2$d) neither stores entries nor emails anybody, so a submission to it goes nowhere.', 'nibwp'),
                isset($form->post_title) ? (string) $form->post_title : '',
                (int) $form->ID
            );
        } elseif (!$admin && !$user) {
            $act_on[] = sprintf(
                /* translators: 1: form title, 2: form ID */
                __('"%1$s" (#%2$d) stores entries but notifies nobody, so somebody has to remember to look.', 'nibwp'),
                isset($form->post_title) ? (string) $form->post_title : '',
                (int) $form->ID
            );
        }
    }

    return [
        'returned' => count($rows),
        'limit' => $limit,
        'forms' => $rows,
        'act_on' => $act_on,
        'entry_counts_available' => $may_count,
        'note' => $may_count
            ? __('Fields are not listed here and are read-only everywhere: a MetForm form is an Elementor document. Use get_form_fields to see them.', 'nibwp')
            : __('Entry counts need the manage_options capability, so they are reported as null rather than as zero.', 'nibwp'),
    ];
}

/**
 * The forms, plainly.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_metform_list_forms(array $input): array|WP_Error
{
    if ($denied = nibwp_metform_require_forms(__('Reading the MetForm forms', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_metform_limit($input['limit'] ?? null);
    $forms = nibwp_metform_form_posts($limit, nibwp_metform_offset($input['offset'] ?? null));
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
            'shortcode' => sprintf('[metform form_id="%d"]', (int) $form->ID),
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
function nibwp_metform_get_form(array $input): array|WP_Error
{
    $form = nibwp_metform_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    if ($denied = nibwp_metform_require_forms(__('Reading a MetForm form', 'nibwp'))) {
        return $denied;
    }

    $id = (int) $form->ID;
    $settings = nibwp_metform_settings($id);
    $report = nibwp_metform_report_settings($settings);
    $fields = nibwp_metform_fields($id);
    $may_count = current_user_can(NIBWP_METFORM_ENTRIES_CAP);

    return [
        'id' => $id,
        'title' => isset($form->post_title) ? (string) $form->post_title : '',
        'status' => isset($form->post_status) ? (string) $form->post_status : '',
        'shortcode' => sprintf('[metform form_id="%d"]', $id),
        'fields' => $fields,
        'field_count' => count($fields),
        'fields_writable' => false,
        'on_submit' => [
            'stores_entries' => nibwp_metform_stores($settings),
            'success_message' => isset($settings['success_message']) ? nibwp_metform_clip($settings['success_message']) : null,
            'redirect_to' => isset($settings['redirect_to']) ? nibwp_metform_clip($settings['redirect_to']) : null,
            'notifies_admin' => nibwp_metform_notifies($settings, 'admin'),
            'notifies_visitor' => nibwp_metform_notifies($settings, 'user'),
        ],
        'entries' => $may_count ? nibwp_metform_entry_count($id) : null,
        'settings' => $report['settings'],
        'withheld' => $report['withheld'],
        'note' => __('The fields come from the form\'s Elementor document and are read-only here. Changing one means editing that document, which is what Elementor is for.', 'nibwp'),
    ];
}

/**
 * The fields, with the reason they cannot be written from here.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_metform_get_form_fields(array $input): array|WP_Error
{
    $form = nibwp_metform_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    if ($denied = nibwp_metform_require_forms(__('Reading a MetForm form\'s fields', 'nibwp'))) {
        return $denied;
    }

    $id = (int) $form->ID;
    $fields = nibwp_metform_fields($id);
    $raw = get_post_meta($id, NIBWP_METFORM_ELEMENTOR_META, true);

    return [
        'form_id' => $id,
        'count' => count($fields),
        'fields' => $fields,
        'writable' => false,
        'stored_in' => NIBWP_METFORM_ELEMENTOR_META,
        'has_elementor_document' => is_string($raw) && $raw !== '',
        'note' => $fields === [] && (!is_string($raw) || $raw === '')
            ? __('This form has no Elementor document yet, so it has no fields. Open it in Elementor and add some.', 'nibwp')
            : __('Fields are Elementor widgets inside the form post\'s _elementor_data, not a settings array, and they are read-only here on purpose: rewriting that document from outside the editor is how a working form turns into a blank space on a live page. Open the form in Elementor to change a field.', 'nibwp'),
    ];
}

/**
 * Which fields a visitor must fill in.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_metform_get_required_fields(array $input): array|WP_Error
{
    $form = nibwp_metform_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    if ($denied = nibwp_metform_require_forms(__('Reading a MetForm form\'s required fields', 'nibwp'))) {
        return $denied;
    }

    $fields = nibwp_metform_fields((int) $form->ID);
    $required = [];
    $optional = [];

    foreach ($fields as $field) {
        $name = isset($field['mf_input_name']) ? (string) $field['mf_input_name'] : (string) ($field['widget_type'] ?? '');

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
        'note' => __('Whether a field is required is the mf_input_required setting on its Elementor widget, so it is reported here and changed in Elementor. Nothing in this integration writes it.', 'nibwp'),
    ];
}

/**
 * One form's settings, allow-listed.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_metform_get_form_settings(array $input): array|WP_Error
{
    $form = nibwp_metform_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    if ($denied = nibwp_metform_require_forms(__('Reading a MetForm form\'s settings', 'nibwp'))) {
        return $denied;
    }

    $report = nibwp_metform_report_settings(nibwp_metform_settings((int) $form->ID));

    return ['form_id' => (int) $form->ID] + $report;
}

/**
 * The two email notifications, and whether either will actually arrive.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_metform_get_notifications(array $input): array|WP_Error
{
    $form = nibwp_metform_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    if ($denied = nibwp_metform_require_forms(__('Reading a MetForm form\'s notifications', 'nibwp'))) {
        return $denied;
    }

    $settings = nibwp_metform_settings((int) $form->ID);
    $admin_on = nibwp_metform_notifies($settings, 'admin');
    $user_on = nibwp_metform_notifies($settings, 'user');
    $to = isset($settings['admin_email_to']) ? trim((string) $settings['admin_email_to']) : '';
    $problems = [];

    if ($admin_on && $to === '') {
        $problems[] = __('The admin notification is on but has no recipient, so it is sent to nobody.', 'nibwp');
    }
    if (!$admin_on && !$user_on) {
        $problems[] = __('Neither notification is on, so a submission to this form emails nobody.', 'nibwp');
    }

    return [
        'form_id' => (int) $form->ID,
        'admin' => [
            'enabled' => $admin_on,
            'to' => $to === '' ? null : nibwp_metform_clip($to),
            'from' => isset($settings['admin_email_from']) ? nibwp_metform_clip($settings['admin_email_from']) : null,
            'reply_to' => isset($settings['admin_email_reply_to']) ? nibwp_metform_clip($settings['admin_email_reply_to']) : null,
            'subject' => isset($settings['admin_email_subject']) ? nibwp_metform_clip($settings['admin_email_subject']) : null,
            'body' => isset($settings['admin_email_body']) ? nibwp_metform_clip($settings['admin_email_body']) : null,
        ],
        'visitor' => [
            'enabled' => $user_on,
            'from' => isset($settings['user_email_from']) ? nibwp_metform_clip($settings['user_email_from']) : null,
            'reply_to' => isset($settings['user_email_reply_to']) ? nibwp_metform_clip($settings['user_email_reply_to']) : null,
            'subject' => isset($settings['user_email_subject']) ? nibwp_metform_clip($settings['user_email_subject']) : null,
            'body' => isset($settings['user_email_body']) ? nibwp_metform_clip($settings['user_email_body']) : null,
        ],
        'problems' => $problems,
        'note' => __('MetForm hands these to wp_mail, so whether they arrive also depends on how this site sends mail — a notification that is on and correct can still be dropped by the recipient\'s server.', 'nibwp'),
    ];
}

/**
 * The site-wide settings, allow-listed.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_metform_get_global_settings(): array|WP_Error
{
    // The global option is where the reCAPTCHA secret and the shared API keys
    // live, so reading it takes the stricter capability even though it is not
    // personal data.
    if (!current_user_can('manage_options')) {
        return nibwp_metform_forbidden(__('Reading MetForm\'s site-wide settings', 'nibwp'), 'manage_options');
    }

    $report = nibwp_metform_report_settings(nibwp_metform_global_settings());

    return [
        'option' => NIBWP_METFORM_GLOBAL_OPTION,
        'settings' => $report['settings'],
        'withheld' => $report['withheld'],
        'note' => __('MetForm merges these over a form\'s own settings when it serves the form, so a key set here applies to every form. Values are reported only for the behaviour settings; every key, token and webhook URL comes back as a name and whether it is set.', 'nibwp'),
    ];
}

/**
 * Entries for one form, or across all forms. Bounded.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_metform_list_entries(array $input): array|WP_Error
{
    // The id is resolved BEFORE the capability is discussed, for the same reason
    // everywhere else in this file: a capability answer must never be given about
    // the wrong object. The cost is that the type refusal tells an unauthorised
    // caller what post type an id is, which is less than any read here returns.
    $form_id = 0;

    if (isset($input['form_id'])) {
        $form = nibwp_metform_form($input['form_id']);
        if ($form instanceof WP_Error) {
            return $form;
        }

        $form_id = (int) $form->ID;
    }

    if ($denied = nibwp_metform_require_entries(__('Listing MetForm entries', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
        return nibwp_metform_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    $limit = nibwp_metform_limit($input['limit'] ?? null);
    $offset = nibwp_metform_offset($input['offset'] ?? null);

    // Two branches rather than one string built up from pieces: the table names
    // come from $wpdb and every value goes through prepare, and keeping each
    // query whole is what makes that visible at a glance.
    if ($form_id > 0) {
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT p.ID, p.post_title, p.post_date_gmt, p.post_status FROM ' . $wpdb->posts . ' p'
            . ' INNER JOIN ' . $wpdb->postmeta . ' m ON m.post_id = p.ID'
            . ' WHERE m.meta_key = %s AND m.meta_value = %d'
            . ' AND p.post_type = %s'
            . ' AND p.post_status NOT IN (\'auto-draft\', \'trash\')'
            . ' ORDER BY p.post_date_gmt DESC LIMIT %d OFFSET %d',
            NIBWP_METFORM_ENTRY_FORM_META,
            $form_id,
            NIBWP_METFORM_ENTRY_TYPE,
            $limit,
            $offset
        ), ARRAY_A);
    } else {
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT p.ID, p.post_title, p.post_date_gmt, p.post_status FROM ' . $wpdb->posts . ' p'
            . ' WHERE p.post_type = %s'
            . ' AND p.post_status NOT IN (\'auto-draft\', \'trash\')'
            . ' ORDER BY p.post_date_gmt DESC LIMIT %d OFFSET %d',
            NIBWP_METFORM_ENTRY_TYPE,
            $limit,
            $offset
        ), ARRAY_A);
    }

    $entries = [];

    foreach ($rows as $row) {
        $id = (int) ($row['ID'] ?? 0);

        // Deliberately no submitted values: a listing is for finding the entry
        // you want, and the values are somebody's personal data. get_entry
        // returns them, one entry at a time, on purpose.
        $entries[] = [
            'id' => $id,
            'title' => (string) ($row['post_title'] ?? ''),
            'submitted' => (string) ($row['post_date_gmt'] ?? ''),
            'status' => (string) ($row['post_status'] ?? ''),
            'form_id' => $form_id > 0 ? $form_id : (int) get_post_meta($id, NIBWP_METFORM_ENTRY_FORM_META, true),
        ];
    }

    return [
        'form_id' => $form_id > 0 ? $form_id : null,
        'limit' => $limit,
        'offset' => $offset,
        'returned' => count($entries),
        'entries' => $entries,
        'values_returned' => false,
        'note' => __('Titles and dates only. Read one entry with get_entry when you need what somebody actually submitted, and prefer count_entries when the question is only whether the form is receiving anything.', 'nibwp'),
    ];
}

/**
 * One entry, with what was submitted.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_metform_get_entry(array $input): array|WP_Error
{
    $entry = nibwp_metform_entry($input['entry_id'] ?? null);
    if ($entry instanceof WP_Error) {
        return $entry;
    }

    if ($denied = nibwp_metform_require_entries(__('Reading a MetForm entry', 'nibwp'))) {
        return $denied;
    }

    $id = (int) $entry->ID;
    $data = get_post_meta($id, NIBWP_METFORM_ENTRY_DATA_META, true);
    $files = get_post_meta($id, NIBWP_METFORM_ENTRY_FILE_META, true);

    // The values are keyed by whatever the form's inputs are called, so there is
    // no shape here to allow-list against. Scrubbing by key at every depth is
    // what stands in for that, and it is why a form with an input named after a
    // credential key cannot smuggle one out through an entry.
    $values = nibwp_metform_scrub(is_array($data) ? $data : []);
    $count = is_array($values) ? count($values) : 0;

    return [
        'id' => $id,
        'form_id' => (int) get_post_meta($id, NIBWP_METFORM_ENTRY_FORM_META, true),
        'title' => isset($entry->post_title) ? (string) $entry->post_title : '',
        'submitted' => isset($entry->post_date_gmt) ? (string) $entry->post_date_gmt : null,
        'field_count' => $count,
        'values' => $values,
        'files' => nibwp_metform_scrub(is_array($files) ? $files : []),
        'ip_and_user_agent_returned' => false,
        'note' => __('This is one person\'s submission. The IP address and browser string MetForm can record alongside it are not returned. Quote only what the question needs, and do not copy it anywhere it was not already.', 'nibwp'),
    ];
}

/**
 * How many entries, over a window.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_metform_count_entries(array $input): array|WP_Error
{
    // Type first, then authority — see nibwp_metform_list_entries().
    $form = nibwp_metform_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    if ($denied = nibwp_metform_require_entries(__('Counting MetForm entries', 'nibwp'))) {
        return $denied;
    }

    $id = (int) $form->ID;
    $days = nibwp_metform_days($input['days'] ?? null);
    $settings = nibwp_metform_settings($id);
    $stores = nibwp_metform_stores($settings);

    return [
        'form_id' => $id,
        'days' => $days,
        'in_period' => nibwp_metform_entry_count($id, $days),
        'all_time' => nibwp_metform_entry_count($id),
        'stores_entries' => $stores,
        'note' => $stores
            ? __('Trashed entries and unfinished submissions are excluded, which is how MetForm counts them too.', 'nibwp')
            : __('This form does not store entries, so a count of zero says nothing about whether anybody submitted it — the submissions were never kept.', 'nibwp'),
    ];
}

/* -------------------------------------------------------------------- audit */

/**
 * Forms that tell nobody when a submission arrives.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_metform_audit_notification(array $input): array|WP_Error
{
    if ($denied = nibwp_metform_require_forms(__('Auditing MetForm notifications', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_metform_limit($input['limit'] ?? null, NIBWP_METFORM_MAX_ROWS);
    $forms = nibwp_metform_form_posts($limit);
    $silent = [];
    $checked = 0;

    foreach ($forms as $form) {
        if (!is_object($form) || !isset($form->ID)) {
            continue;
        }

        $checked++;
        $settings = nibwp_metform_settings((int) $form->ID);
        $admin = nibwp_metform_notifies($settings, 'admin');
        $user = nibwp_metform_notifies($settings, 'user');
        $to = isset($settings['admin_email_to']) ? trim((string) $settings['admin_email_to']) : '';

        if ($admin && $to !== '') {
            continue;
        }

        if (!$admin && !$user) {
            $silent[] = [
                'id' => (int) $form->ID,
                'title' => isset($form->post_title) ? (string) $form->post_title : '',
                'reason' => 'no_notification_enabled',
                'stores_entries' => nibwp_metform_stores($settings),
            ];
            continue;
        }

        if ($admin && $to === '') {
            $silent[] = [
                'id' => (int) $form->ID,
                'title' => isset($form->post_title) ? (string) $form->post_title : '',
                'reason' => 'admin_notification_has_no_recipient',
                'stores_entries' => nibwp_metform_stores($settings),
            ];
        }
    }

    return [
        'forms_checked' => $checked,
        'limit' => $limit,
        'count' => count($silent),
        'forms' => $silent,
        'note' => __('A form with no notification is not broken, but nobody is told when somebody uses it — if it also does not store entries, the submission is gone. set_admin_notification fixes the common case.', 'nibwp'),
    ];
}

/**
 * Forms that have never received an entry.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_metform_audit_entries(array $input): array|WP_Error
{
    // This reads entry counts, so it takes the entries capability even though it
    // returns no entry: a zero here is still a statement about submissions.
    if ($denied = nibwp_metform_require_entries(__('Auditing MetForm forms for entries', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
        return nibwp_metform_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    $limit = nibwp_metform_limit($input['limit'] ?? null, NIBWP_METFORM_MAX_ROWS);
    $forms = nibwp_metform_form_posts($limit);

    // One grouped query rather than a count per form: the form list is bounded,
    // but a bounded number of queries is still a query per form on a site with
    // two hundred forms.
    $rows = (array) $wpdb->get_results($wpdb->prepare(
        'SELECT m.meta_value AS form_id, COUNT(p.ID) AS entries FROM ' . $wpdb->postmeta . ' m'
        . ' INNER JOIN ' . $wpdb->posts . ' p ON p.ID = m.post_id'
        . ' WHERE m.meta_key = %s AND p.post_type = %s'
        . ' AND p.post_status NOT IN (\'auto-draft\', \'trash\')'
        . ' GROUP BY m.meta_value ORDER BY entries DESC LIMIT %d',
        NIBWP_METFORM_ENTRY_FORM_META,
        NIBWP_METFORM_ENTRY_TYPE,
        NIBWP_METFORM_MAX_FORMS
    ), ARRAY_A);

    $counts = [];

    foreach ($rows as $row) {
        $counts[(int) ($row['form_id'] ?? 0)] = (int) ($row['entries'] ?? 0);
    }

    $empty = [];
    $checked = 0;

    foreach ($forms as $form) {
        if (!is_object($form) || !isset($form->ID)) {
            continue;
        }

        $checked++;
        $id = (int) $form->ID;

        if (($counts[$id] ?? 0) > 0) {
            continue;
        }

        $settings = nibwp_metform_settings($id);
        $empty[] = [
            'id' => $id,
            'title' => isset($form->post_title) ? (string) $form->post_title : '',
            'stores_entries' => nibwp_metform_stores($settings),
            'notifies' => nibwp_metform_notifies($settings, 'admin') || nibwp_metform_notifies($settings, 'user'),
            'created' => isset($form->post_date_gmt) ? (string) $form->post_date_gmt : null,
        ];
    }

    return [
        'forms_checked' => $checked,
        'limit' => $limit,
        'count' => count($empty),
        'forms' => $empty,
        // The grouped query is itself bounded, so on a site with more forms than
        // that bound a form with entries could fall outside it and be reported
        // here as empty. Saying so beats quietly being wrong.
        'may_be_incomplete' => count($rows) >= NIBWP_METFORM_MAX_FORMS,
        'note' => __('A form with no stored entries has either never been submitted, or is not storing what it receives — the stores_entries flag on each row says which question to ask. It is not on its own a reason to delete anything.', 'nibwp'),
    ];
}

/* ------------------------------------------------------------------- writes */

/**
 * Create a form.
 *
 * MetForm's own Builder is used when it is there (core/forms/builder.php:83): it
 * writes the settings defaults, the Elementor canvas page template and the editor
 * metadata that a form needs before Elementor will open it. Reimplementing that
 * from the outside is how a form ends up looking created and refusing to edit.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_metform_create_form(array $input): array|WP_Error
{
    $cap = nibwp_metform_forms_cap();

    if (!current_user_can($cap) && !current_user_can('manage_options')) {
        return nibwp_metform_forbidden(__('Creating a MetForm form', 'nibwp'), $cap . ' / manage_options');
    }

    $title = isset($input['title']) ? sanitize_text_field((string) $input['title']) : '';

    if ($title === '') {
        return nibwp_metform_err('missing_title', __('Give the new form a title as title.', 'nibwp'), 422);
    }

    $builder = 'MetForm\\Core\\Forms\\Builder';

    if (!class_exists($builder) || !method_exists($builder, 'instance')) {
        return nibwp_metform_err(
            'no_builder',
            __('This version of MetForm does not expose the form builder where this integration can reach it, so a form cannot be created from here. Use the MetForm screen in wp-admin.', 'nibwp'),
            500
        );
    }

    $instance = $builder::instance();

    if (!is_object($instance) || !method_exists($instance, 'create_form')) {
        return nibwp_metform_err(
            'no_builder',
            __('This version of MetForm does not expose the form builder where this integration can reach it, so a form cannot be created from here. Use the MetForm screen in wp-admin.', 'nibwp'),
            500
        );
    }

    $created = $instance->create_form($title, 0, []);

    if ($created instanceof WP_Error) {
        return $created;
    }

    $id = is_numeric($created) ? (int) $created : 0;

    if ($id <= 0) {
        return nibwp_metform_err('create_failed', __('MetForm did not return a form ID, so the form may not have been created. Check the forms list.', 'nibwp'), 500);
    }

    return [
        'created' => true,
        'form_id' => $id,
        'title' => $title,
        'shortcode' => sprintf('[metform form_id="%d"]', $id),
        'field_count' => 0,
        'note' => __('The form exists and stores entries, but it has no fields yet: fields are Elementor widgets, and this integration does not write them. Open the form in Elementor to add them, then place the shortcode on a page.', 'nibwp'),
    ];
}

/**
 * Rename a form.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_metform_rename_form(array $input): array|WP_Error
{
    $form = nibwp_metform_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    $id = (int) $form->ID;

    if ($denied = nibwp_metform_can_touch_form($id, __('Renaming a MetForm form', 'nibwp'))) {
        return $denied;
    }

    $title = isset($input['title']) ? sanitize_text_field((string) $input['title']) : '';

    if ($title === '') {
        return nibwp_metform_err('missing_title', __('Give the new title as title.', 'nibwp'), 422);
    }

    $was = isset($form->post_title) ? (string) $form->post_title : '';

    $updated = wp_update_post(['ID' => $id, 'post_title' => $title], true);

    if ($updated instanceof WP_Error) {
        return $updated;
    }

    // MetForm keeps a second copy of the title inside the settings array and
    // shows THAT one on its own screens (core/forms/action.php:118-126), so
    // updating only the post leaves the two disagreeing.
    $written = nibwp_metform_write_settings($id, ['form_title' => $title]);

    if ($written instanceof WP_Error) {
        return $written;
    }

    return [
        'renamed' => true,
        'form_id' => $id,
        'was' => $was,
        'now' => $title,
        'note' => __('The shortcode uses the form ID, not the title, so anything already published keeps working.', 'nibwp'),
    ];
}

/**
 * Move a form to the trash. Reversible.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_metform_trash_form(array $input): array|WP_Error
{
    $form = nibwp_metform_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    $id = (int) $form->ID;

    if ($denied = nibwp_metform_can_touch_form($id, __('Trashing a MetForm form', 'nibwp'), 'delete_post')) {
        return $denied;
    }

    $trashed = wp_trash_post($id);

    if (!$trashed) {
        return nibwp_metform_err('trash_failed', __('WordPress did not trash the form.', 'nibwp'), 500);
    }

    return [
        'trashed' => true,
        'form_id' => $id,
        'entries_kept' => true,
        'note' => __('The form is in the trash and can be restored. Its entries are untouched. Anywhere the shortcode is published now renders nothing, so check the pages that used it.', 'nibwp'),
    ];
}

/**
 * Delete a form for good.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_metform_delete_form(array $input): array|WP_Error
{
    $form = nibwp_metform_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    $id = (int) $form->ID;

    if ($denied = nibwp_metform_can_touch_form($id, __('Permanently deleting a MetForm form', 'nibwp'), 'delete_post')) {
        return $denied;
    }

    $title = isset($form->post_title) ? (string) $form->post_title : '';
    $entries = current_user_can(NIBWP_METFORM_ENTRIES_CAP) ? nibwp_metform_entry_count($id) : null;

    $deleted = wp_delete_post($id, true);

    if (!$deleted) {
        return nibwp_metform_err('delete_failed', __('WordPress did not delete the form.', 'nibwp'), 500);
    }

    return [
        'deleted' => true,
        'form_id' => $id,
        'title' => $title,
        'entries_left_behind' => $entries,
        'note' => __('Gone, with its settings and its Elementor document, and there is no undo. Its entries are NOT deleted: they stay as orphaned entry posts, which is also why they can no longer be read through this form. Say the title back to whoever asked.', 'nibwp'),
    ];
}

/**
 * Change what a visitor sees after submitting.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_metform_set_success_message(array $input): array|WP_Error
{
    $form = nibwp_metform_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    $id = (int) $form->ID;

    if ($denied = nibwp_metform_can_touch_form($id, __('Changing a MetForm success message', 'nibwp'))) {
        return $denied;
    }

    if (!isset($input['message']) || !is_string($input['message'])) {
        return nibwp_metform_err('missing_message', __('Give the message as message.', 'nibwp'), 422);
    }

    $message = wp_kses_post($input['message']);

    if (trim($message) === '') {
        return nibwp_metform_err('empty_message', __('An empty success message leaves the visitor with no confirmation at all. Give some text.', 'nibwp'), 422);
    }

    $written = nibwp_metform_write_settings($id, ['success_message' => $message]);

    if ($written instanceof WP_Error) {
        return $written;
    }

    return [
        'updated' => true,
        'form_id' => $id,
        'success_message' => nibwp_metform_clip($message),
        'note' => __('Shown in place of the form after a successful submission, unless a redirect is set — a redirect takes the visitor away before they read it.', 'nibwp'),
    ];
}

/**
 * Change where a visitor goes after submitting.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_metform_set_redirect(array $input): array|WP_Error
{
    $form = nibwp_metform_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    $id = (int) $form->ID;

    if ($denied = nibwp_metform_can_touch_form($id, __('Changing a MetForm redirect', 'nibwp'))) {
        return $denied;
    }

    if (!isset($input['url']) || !is_string($input['url'])) {
        return nibwp_metform_err('missing_url', __('Give the destination as url, or an empty string to clear the redirect.', 'nibwp'), 422);
    }

    $raw = trim($input['url']);

    // MetForm stores the destination in redirect_to and reads it straight back
    // out after a submission (core/entries/action.php:740) — it is the URL
    // itself, not a switch, so an empty value is how a redirect is removed.
    if ($raw === '') {
        $written = nibwp_metform_write_settings($id, ['redirect_to' => '']);

        if ($written instanceof WP_Error) {
            return $written;
        }

        return [
            'updated' => true,
            'form_id' => $id,
            'redirect_to' => null,
            'note' => __('The redirect is cleared, so the success message is what the visitor sees now.', 'nibwp'),
        ];
    }

    $scheme = strtolower((string) wp_parse_url($raw, PHP_URL_SCHEME));

    if (!in_array($scheme, ['http', 'https'], true)) {
        return nibwp_metform_err(
            'bad_url',
            sprintf(
                /* translators: %s: the rejected value */
                __('"%s" is not an http or https URL. A redirect that does not resolve loses the submission\'s confirmation.', 'nibwp'),
                $raw
            ),
            422
        );
    }

    $url = esc_url_raw($raw);

    if ($url === '') {
        return nibwp_metform_err('bad_url', __('That URL did not survive sanitising, so it was not written.', 'nibwp'), 422);
    }

    $written = nibwp_metform_write_settings($id, ['redirect_to' => $url]);

    if ($written instanceof WP_Error) {
        return $written;
    }

    return [
        'updated' => true,
        'form_id' => $id,
        'redirect_to' => $url,
        'note' => __('A redirect replaces the success message, so whatever that page says is now the confirmation the visitor gets.', 'nibwp'),
    ];
}

/**
 * Switch the admin notification on or off, and set its recipient and text.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_metform_set_admin_notification(array $input): array|WP_Error
{
    $form = nibwp_metform_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    $id = (int) $form->ID;

    if ($denied = nibwp_metform_can_touch_form($id, __('Changing a MetForm admin notification', 'nibwp'))) {
        return $denied;
    }

    $settings = nibwp_metform_settings($id);
    $changes = [];

    if (isset($input['email_to'])) {
        $to = nibwp_metform_recipients((string) $input['email_to']);

        if ($to instanceof WP_Error) {
            return $to;
        }

        $changes['admin_email_to'] = $to;
    }

    if (isset($input['subject']) && is_string($input['subject'])) {
        $changes['admin_email_subject'] = sanitize_text_field($input['subject']);
    }

    if (isset($input['body']) && is_string($input['body'])) {
        $changes['admin_email_body'] = wp_kses_post($input['body']);
    }

    if (isset($input['enabled'])) {
        $on = (bool) $input['enabled'];

        // MetForm compares this to 1 (core/entries/action.php:794), and its own
        // screens unset the key when the checkbox is cleared. Writing '' rather
        // than '0' is not safe to assume, so '0' is written: it fails the == 1
        // test the same way an absent key does.
        $changes['enable_admin_notification'] = $on ? '1' : '0';

        $to = isset($changes['admin_email_to'])
            ? (string) $changes['admin_email_to']
            : (isset($settings['admin_email_to']) ? trim((string) $settings['admin_email_to']) : '');

        if ($on && $to === '') {
            return nibwp_metform_err(
                'no_recipient',
                __('Switching the admin notification on with no recipient would send it nowhere. Pass email_to as well.', 'nibwp'),
                422
            );
        }
    }

    $written = nibwp_metform_write_settings($id, $changes);

    if ($written instanceof WP_Error) {
        return $written;
    }

    $after = nibwp_metform_settings($id);

    return [
        'updated' => true,
        'form_id' => $id,
        'changed' => $written,
        'enabled' => nibwp_metform_notifies($after, 'admin'),
        'to' => isset($after['admin_email_to']) ? nibwp_metform_clip($after['admin_email_to']) : null,
        'note' => __('MetForm sends this with wp_mail, so it arrives only as reliably as this site sends mail. Submit the form once and check that it lands.', 'nibwp'),
    ];
}

/**
 * Switch the visitor's confirmation email on or off, and set its text.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_metform_set_user_notification(array $input): array|WP_Error
{
    $form = nibwp_metform_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    $id = (int) $form->ID;

    if ($denied = nibwp_metform_can_touch_form($id, __('Changing a MetForm visitor notification', 'nibwp'))) {
        return $denied;
    }

    $changes = [];

    if (isset($input['subject']) && is_string($input['subject'])) {
        $changes['user_email_subject'] = sanitize_text_field($input['subject']);
    }

    if (isset($input['body']) && is_string($input['body'])) {
        $changes['user_email_body'] = wp_kses_post($input['body']);
    }

    if (isset($input['enabled'])) {
        $changes['enable_user_notification'] = ((bool) $input['enabled']) ? '1' : '0';
    }

    $written = nibwp_metform_write_settings($id, $changes);

    if ($written instanceof WP_Error) {
        return $written;
    }

    $after = nibwp_metform_settings($id);

    return [
        'updated' => true,
        'form_id' => $id,
        'changed' => $written,
        'enabled' => nibwp_metform_notifies($after, 'user'),
        'note' => __('This one goes to the address the visitor typed into the form\'s email field, so a form with no email field has nowhere to send it.', 'nibwp'),
    ];
}

/**
 * Delete one entry for good.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_metform_delete_entry(array $input): array|WP_Error
{
    $entry = nibwp_metform_entry($input['entry_id'] ?? null);
    if ($entry instanceof WP_Error) {
        return $entry;
    }

    $id = (int) $entry->ID;

    if ($denied = nibwp_metform_require_entries(__('Permanently deleting a MetForm entry', 'nibwp'))) {
        return $denied;
    }

    // The entries CPT does set map_meta_cap (core/entries/cpt.php:49), so unlike
    // the forms type the per-object question resolves properly here and is asked
    // on its own terms.
    if (!current_user_can('delete_post', $id)) {
        return nibwp_metform_forbidden(__('Permanently deleting this MetForm entry', 'nibwp'), 'delete_post');
    }

    $form_id = (int) get_post_meta($id, NIBWP_METFORM_ENTRY_FORM_META, true);
    $title = isset($entry->post_title) ? (string) $entry->post_title : '';

    $deleted = wp_delete_post($id, true);

    if (!$deleted) {
        return nibwp_metform_err('delete_failed', __('WordPress did not delete the entry.', 'nibwp'), 500);
    }

    return [
        'deleted' => true,
        'entry_id' => $id,
        'form_id' => $form_id,
        'title' => $title,
        'note' => __('That person\'s submission is gone and there is no undo — not the trash, deleted. Any file they uploaded stays in the media library and has to be removed separately if that was the point.', 'nibwp'),
    ];
}

/**
 * Validate one or more notification recipients.
 *
 * MetForm accepts a comma-separated list here, so each address is checked rather
 * than the string as a whole: one typo in a list of three silently drops that
 * recipient and nothing says so.
 *
 * @return string|WP_Error
 */
function nibwp_metform_recipients(string $raw): string|WP_Error
{
    $parts = array_filter(array_map('trim', explode(',', $raw)));

    if ($parts === []) {
        return nibwp_metform_err('missing_recipient', __('Give at least one recipient as email_to.', 'nibwp'), 422);
    }

    $clean = [];

    foreach ($parts as $part) {
        $address = sanitize_email((string) $part);

        if ($address === '' || !is_email($address)) {
            return nibwp_metform_err(
                'bad_recipient',
                sprintf(
                    /* translators: %s: the rejected address */
                    __('"%s" is not an email address.', 'nibwp'),
                    (string) $part
                ),
                422
            );
        }

        $clean[] = $address;
    }

    return implode(',', $clean);
}
