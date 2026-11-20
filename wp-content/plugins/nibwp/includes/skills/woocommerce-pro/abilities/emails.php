<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * WooCommerce Pro — nibwp/wcpro-emails.
 *
 * The notifications, which are the part of a store nobody looks at until a
 * customer says they never heard anything. WooCommerce registers around fifteen
 * emails and keeps each one's configuration in its own option row —
 * woocommerce_<id>_settings — with the store-wide styling in eight more rows
 * beside them. None of that is reachable through the Free wc-* abilities, and
 * none of it is where an agent would guess: there is no email post type, no
 * email CRUD object, and the recipient of a customer email is not stored at all
 * because WooCommerce fills it in from the order at send time.
 *
 * The action this hub exists for is list_disabled_emails. A switched-off
 * "customer completed order" is a support ticket nobody files: the shop keeps
 * taking orders, the customer hears nothing, and the only trace is an unchecked
 * box on a settings screen four clicks deep.
 *
 * Two rules, because email is the one thing here that leaves the building:
 *
 *   1. send_test_email delivers real mail to a real address. It asks for
 *      manage_woocommerce at the send site, not only at the door, and it
 *      validates the address with is_email() first.
 *   2. Nothing in a response carries a credential. An email's stored settings
 *      are filtered through an allow-list before they are returned, so an SMTP
 *      password or an API key a mail plugin parked in there is never handed back
 *      — the allow-list refuses an unknown key by default, which a denylist of
 *      today's dangerous names would not.
 */

require_once __DIR__ . '/../lib/guard.php';
require_once __DIR__ . '/../lib/sanitize.php';

/**
 * The action enum. Single source of truth — the registration and
 * tests/skills/woocommerce-pro-check.php both read this, and the switch in
 * nibwp_wcpro_emails_execute() must match it exactly in both directions.
 *
 * @return array<int, string>
 */
function nibwp_wcpro_emails_actions(): array
{
    return [
        // The registered emails.
        'list_emails',
        'get_email',
        'update_email',
        'list_disabled_emails',
        // The one action that leaves the building.
        'send_test_email',
        // Store-wide appearance.
        'get_email_settings',
        'update_email_settings',
    ];
}

nibwp_wcpro_register_hub(
    'nibwp/wcpro-emails',
    __('WooCommerce Pro — store emails and notifications', 'nibwp'),
    'Read and change the WooCommerce notification emails: list every registered email with its id, recipient, subject, heading and whether it is enabled, read one in full, change its enabled flag, recipient, subject, heading and additional content, report which emails are switched off and what each silence costs, and read or write the store-wide email appearance — from name, from address, header image, footer text and the base colours. action:"send_test_email" SENDS A REAL EMAIL to the address given; every other action only reads or writes settings. Gateway and mail-plugin credentials are never returned.',
    nibwp_wcpro_emails_actions(),
    [
        'email_id' => ['type' => 'string', 'description' => 'Which email, by WooCommerce id: new_order, cancelled_order, failed_order, customer_on_hold_order, customer_processing_order, customer_completed_order, customer_refunded_order, customer_invoice, customer_note, customer_reset_password, customer_new_account. list_emails returns the ids this store actually has.'],
        'email' => [
            'type' => 'object',
            'description' => 'For update_email, only the keys you want changed: {enabled:true|false, recipient:"a@b.com, c@d.com" (admin emails only), subject:"…", heading:"…", additional_content:"…"}. Unmentioned keys are left alone. An empty subject or heading falls back to WooCommerce\'s default for that email.',
        ],
        'recipient' => ['type' => 'string', 'description' => 'For send_test_email: the address the test is DELIVERED to. This action sends real mail — use the store owner\'s own address, never a customer\'s.'],
        'settings' => [
            'type' => 'object',
            'description' => 'For update_email_settings, only the keys you want changed: {from_name, from_address, header_image (http(s) URL, or "" to clear), footer_text, base_color, background_color, body_background_color, text_color}. Colours are #rrggbb.',
        ],
        'disabled_only' => ['type' => 'boolean', 'default' => false, 'description' => 'For list_emails: only the emails that are switched off.'],
    ],
    'nibwp_wcpro_emails_execute',
    implode("\n", [
        'Read first: action:"list_disabled_emails" writes nothing and is the answer to "why did my customer not get an email?" more often than anything else in this skill. It names each switched-off email and what the silence costs.',
        'list_emails gives every registered email with its id, recipient, subject, heading and enabled flag. get_email adds the defaults, the template paths and the stored settings this hub is willing to return.',
        'update_email { email_id, email:{…} } changes one email. A CUSTOMER email has no settable recipient — WooCommerce addresses it from the order — so passing one is refused rather than stored where it would be ignored. An admin email takes a comma-separated list and every address in it is validated.',
        'An empty subject or heading is not a blank email: WooCommerce falls back to its own default for that email, and get_email reports both the stored value and the default so you can tell which is in force.',
        'send_test_email { email_id, recipient } SENDS A REAL EMAIL. It needs manage_woocommerce, the address must pass is_email(), and it renders the email\'s own subject, heading and additional content inside the store\'s email template so the styling and deliverability are both being tested. Placeholders that need an order ({order_number}, {order_date}) stay literal in a test, because a test never invents a customer\'s order. Ask the user for the address; do not guess one, and never send a test to a customer.',
        'get_email_settings / update_email_settings are the store-wide appearance rows — from name, from address, header image, footer text, base colours. They apply to every email at once.',
        'Nothing here returns a credential. An email\'s stored settings are filtered to the keys this hub understands and the rest are reported by NAME only, because a mail plugin parks API keys in the same rows.',
    ]),
);

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_emails_execute(array $input): array|WP_Error
{
    $guard = nibwp_wcpro_guard($input, false);
    if (is_wp_error($guard)) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');

    // One bar for the whole hub. An email's configuration has no object to ask
    // about — it is the store's own — and the reads expose the addresses the
    // store notifies, so reading is not a lower bar than writing here.
    $denied = nibwp_wcpro_require_manage('The store email settings');
    if ($denied !== null) {
        return $denied;
    }

    switch ($action) {
        case 'list_emails':
            return nibwp_wcpro_list_emails($input);

        case 'get_email':
            return nibwp_wcpro_get_email($input);

        case 'update_email':
            return nibwp_wcpro_update_email($input);

        case 'list_disabled_emails':
            return nibwp_wcpro_list_disabled_emails();

        case 'send_test_email':
            return nibwp_wcpro_send_test_email($input);

        case 'get_email_settings':
            return nibwp_wcpro_get_email_settings();

        case 'update_email_settings':
            return nibwp_wcpro_update_email_settings((array) ($input['settings'] ?? []));
    }

    return nibwp_wcpro_unknown_action($action, nibwp_wcpro_emails_actions());
}

/* ------------------------------------------------------------------ helpers */

/**
 * Every email WooCommerce has registered, keyed by its own id rather than by the
 * class name the mailer keys them with.
 *
 * Returns a WP_Error rather than an empty list when the mailer is unreachable: a
 * store mid-upgrade would otherwise be told it has no notifications, which is a
 * worse answer than "ask again".
 *
 * @return array<string, object>|WP_Error
 */
function nibwp_wcpro_email_objects(): array|WP_Error
{
    $denied = nibwp_wcpro_require_function('WC', 'Reading the store emails');
    if ($denied !== null) {
        return $denied;
    }

    $wc = WC();
    if (!is_object($wc) || !method_exists($wc, 'mailer')) {
        return nibwp_wcpro_err('mailer_unavailable', 'WooCommerce has not loaded its mailer on this request, so the emails cannot be read.', 409);
    }

    $mailer = $wc->mailer();
    if (!is_object($mailer) || !method_exists($mailer, 'get_emails')) {
        return nibwp_wcpro_err('mailer_unavailable', 'WooCommerce\'s mailer has no get_emails() on this site, so the emails cannot be read.', 409);
    }

    $emails = [];
    foreach ((array) $mailer->get_emails() as $email) {
        if (!is_object($email)) {
            continue;
        }
        $id = (string) ($email->id ?? '');
        if ($id !== '') {
            $emails[$id] = $email;
        }
    }

    return $emails;
}

/** One email by id, or a refusal that lists the ids this store has. */
function nibwp_wcpro_email_object(mixed $email_id): object
{
    $id = nibwp_wcpro_text($email_id, 80);
    if ($id === '') {
        return nibwp_wcpro_err('missing_email_id', 'Provide email_id. action:"list_emails" returns the ids this store has.', 422);
    }

    $emails = nibwp_wcpro_email_objects();
    if (is_wp_error($emails)) {
        return $emails;
    }
    if (!isset($emails[$id])) {
        return nibwp_wcpro_err(
            'unknown_email',
            sprintf('No WooCommerce email with id "%s". This store has: %s.', $id, implode(', ', array_keys($emails))),
            404,
        );
    }

    return $emails[$id];
}

/** Is this an email addressed to the customer rather than to the shop? */
function nibwp_wcpro_email_is_customer(object $email): bool
{
    if (method_exists($email, 'is_customer_email')) {
        return (bool) $email->is_customer_email();
    }

    return (bool) ($email->customer_email ?? false);
}

/**
 * A stored option, with WooCommerce's own default when it is empty.
 *
 * get_subject() and get_heading() would run the value through format_string()
 * and replace placeholders against whatever object the email happens to hold —
 * nothing, here. The stored string is what the owner edits and what has to be
 * given back for an edit to round-trip, so that is what this returns.
 */
function nibwp_wcpro_email_stored(object $email, string $key, string $default_method): string
{
    $stored = method_exists($email, 'get_option') ? (string) $email->get_option($key, '') : '';
    if (trim($stored) !== '') {
        return $stored;
    }

    return method_exists($email, $default_method) ? (string) $email->{$default_method}() : '';
}

/**
 * The email settings keys this hub is willing to return a VALUE for.
 *
 * An allow-list, not a denylist of dangerous names: a third-party email class
 * stores whatever it likes in the same option row, and an SMTP password or an
 * API key added by a plugin next year passes a denylist written today. Anything
 * not named here is reported by key name only, exactly as the store hub reports
 * a payment gateway's fields.
 *
 * @return array<int, string>
 */
function nibwp_wcpro_email_setting_allowlist(): array
{
    return [
        'enabled',
        'recipient',
        'subject',
        'heading',
        'additional_content',
        'email_type',
        'subject_full',
        'subject_partial',
        'heading_full',
        'heading_partial',
        'subject_downloadable',
        'heading_downloadable',
        'cc',
        'bcc',
    ];
}

/**
 * Split an email's stored settings into the values that may be returned and the
 * names of everything else.
 *
 * @param array<string, mixed> $raw
 * @return array{settings: array<string, string>, other_setting_keys: array<int, string>}
 */
function nibwp_wcpro_email_safe_settings(array $raw): array
{
    $allowed = nibwp_wcpro_email_setting_allowlist();
    $settings = [];
    $other = [];

    foreach ($raw as $key => $value) {
        $key = (string) $key;
        if (in_array($key, $allowed, true)) {
            $settings[$key] = is_scalar($value) ? (string) $value : '';
            continue;
        }
        $other[] = $key;
    }

    sort($other);

    return ['settings' => $settings, 'other_setting_keys' => $other];
}

/**
 * One row of the list. Deliberately does not call get_recipient(): on a customer
 * email that property is null until an order sets it, and explode() on null is a
 * deprecation notice in the middle of a JSON response.
 *
 * @return array<string, mixed>
 */
function nibwp_wcpro_email_summary(object $email): array
{
    $is_customer = nibwp_wcpro_email_is_customer($email);

    return [
        'id'          => (string) ($email->id ?? ''),
        'title'       => (string) ($email->title ?? ''),
        'enabled'     => method_exists($email, 'is_enabled') ? (bool) $email->is_enabled() : ($email->enabled ?? 'no') === 'yes',
        'recipient'   => $is_customer ? '' : (string) ($email->recipient ?? ''),
        'goes_to_customer' => $is_customer,
        'subject'     => nibwp_wcpro_email_stored($email, 'subject', 'get_default_subject'),
        'heading'     => nibwp_wcpro_email_stored($email, 'heading', 'get_default_heading'),
        'manual'      => (bool) ($email->manual ?? false),
    ];
}

/**
 * Why each email matters, for the store owner who is deciding whether a switched
 * off one is a problem.
 *
 * @return array<string, string>
 */
function nibwp_wcpro_email_criticality(): array
{
    return [
        'customer_completed_order'  => 'The customer is never told their order shipped. This is the commonest "I heard nothing" complaint, and it never reaches you as a bug report.',
        'customer_processing_order' => 'The customer gets no confirmation that their payment landed, so they either order again or ask you whether it worked.',
        'customer_on_hold_order'    => 'An order awaiting payment — bank transfer, cheque — is never explained to the customer, so it is never paid.',
        'customer_invoice'          => 'You cannot send an invoice or a payment link from the order screen; the button sends nothing.',
        'customer_refunded_order'   => 'A refund happens silently, which is the version of a refund a customer disputes with their bank.',
        'customer_new_account'      => 'A new account is created with no welcome and, where WooCommerce generates the password, no way to sign in.',
        'customer_reset_password'   => 'Password resets stop working for every customer. They cannot tell you, because telling you needs their account.',
        'new_order'                 => 'YOU are not told an order came in. The shop keeps selling and nothing gets packed.',
        'failed_order'              => 'You never learn that payments are failing, which is how a broken gateway runs for a week.',
        'cancelled_order'           => 'You are not told an order was cancelled, so it may still be picked and shipped.',
        'customer_note'             => 'A note you add for the customer is never delivered, while the order screen says it was.',
    ];
}

/* ------------------------------------------------------------------- reading */

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_list_emails(array $input): array|WP_Error
{
    $emails = nibwp_wcpro_email_objects();
    if (is_wp_error($emails)) {
        return $emails;
    }

    $disabled_only = nibwp_wcpro_bool($input['disabled_only'] ?? false);

    $items = [];
    $disabled = 0;
    foreach ($emails as $email) {
        $summary = nibwp_wcpro_email_summary($email);
        if (!$summary['enabled']) {
            $disabled++;
        }
        if ($disabled_only && $summary['enabled']) {
            continue;
        }
        $items[] = $summary;
    }

    return nibwp_wcpro_ok(
        'list_emails',
        sprintf('%d email(s) registered, %d switched off.', count($emails), $disabled),
        [
            'items' => $items,
            'count' => count($items),
            'next_steps' => $disabled > 0
                ? ['Some emails are switched off. action:"list_disabled_emails" says what each silence costs.']
                : [],
        ],
    );
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_get_email(array $input): array|WP_Error
{
    $email = nibwp_wcpro_email_object($input['email_id'] ?? '');
    if (is_wp_error($email)) {
        return $email;
    }

    $safe = nibwp_wcpro_email_safe_settings((array) ($email->settings ?? []));
    $is_customer = nibwp_wcpro_email_is_customer($email);

    $result = nibwp_wcpro_email_summary($email) + [
        'description'        => (string) ($email->description ?? ''),
        'additional_content' => method_exists($email, 'get_option') ? (string) $email->get_option('additional_content', '') : '',
        'default_subject'    => method_exists($email, 'get_default_subject') ? (string) $email->get_default_subject() : '',
        'default_heading'    => method_exists($email, 'get_default_heading') ? (string) $email->get_default_heading() : '',
        'email_type'         => method_exists($email, 'get_email_type') ? (string) $email->get_email_type() : '',
        'template_html'      => (string) ($email->template_html ?? ''),
        'template_plain'     => (string) ($email->template_plain ?? ''),
        'option_key'         => method_exists($email, 'get_option_key') ? (string) $email->get_option_key() : '',
        'recipient_is_editable' => !$is_customer,
    ] + $safe;

    return nibwp_wcpro_ok(
        'get_email',
        sprintf('Email "%s" (%s), %s.', $result['id'], $result['title'], $result['enabled'] ? 'enabled' : 'SWITCHED OFF'),
        [
            'result' => $result,
            'next_steps' => $safe['other_setting_keys'] === []
                ? []
                : ['This email has settings beyond the ones this hub understands. Their names are listed; their values are not returned, because a mail plugin stores credentials in the same row.'],
        ],
    );
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_list_disabled_emails(): array|WP_Error
{
    $emails = nibwp_wcpro_email_objects();
    if (is_wp_error($emails)) {
        return $emails;
    }

    $why = nibwp_wcpro_email_criticality();
    $items = [];
    $steps = [];

    foreach ($emails as $id => $email) {
        $summary = nibwp_wcpro_email_summary($email);
        if ($summary['enabled']) {
            continue;
        }
        $consequence = (string) ($why[$id] ?? 'Nothing is sent for this event.');
        $items[] = [
            'id'    => $id,
            'title' => $summary['title'],
            'goes_to_customer' => $summary['goes_to_customer'],
            'consequence' => $consequence,
            'known_critical' => isset($why[$id]),
        ];
        if (isset($why[$id])) {
            $steps[] = sprintf('%s is off — %s Switch it on with update_email { email_id:"%s", email:{ enabled:true } }.', $id, $consequence, $id);
        }
    }

    return nibwp_wcpro_ok(
        'list_disabled_emails',
        $items === []
            ? sprintf('All %d registered emails are switched on.', count($emails))
            : sprintf('%d of %d emails are switched off.', count($items), count($emails)),
        [
            'items' => $items,
            'count' => count($items),
            'next_steps' => $steps,
        ],
    );
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_get_email_settings(): array|WP_Error
{
    $values = [];
    foreach (nibwp_wcpro_email_settings_map() as $key => $option) {
        $values[$key] = (string) get_option($option, '');
    }

    $missing = [];
    if (trim($values['from_address']) === '') {
        $missing[] = 'No from address is set, so WooCommerce sends as the WordPress default and the mail is more likely to be filed as spam.';
    }
    if (trim($values['from_name']) === '') {
        $missing[] = 'No from name is set, so customers see an address rather than the shop.';
    }

    return nibwp_wcpro_ok('get_email_settings', 'Store-wide email appearance.', [
        'result' => $values,
        'next_steps' => $missing,
    ]);
}

/**
 * The store-wide appearance rows, mapped to the option names WooCommerce keeps
 * them in.
 *
 * @return array<string, string>
 */
function nibwp_wcpro_email_settings_map(): array
{
    return [
        'from_name'             => 'woocommerce_email_from_name',
        'from_address'          => 'woocommerce_email_from_address',
        'header_image'          => 'woocommerce_email_header_image',
        'footer_text'           => 'woocommerce_email_footer_text',
        'base_color'            => 'woocommerce_email_base_color',
        'background_color'      => 'woocommerce_email_background_color',
        'body_background_color' => 'woocommerce_email_body_background_color',
        'text_color'            => 'woocommerce_email_text_color',
    ];
}

/* ------------------------------------------------------------------- writing */

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_update_email(array $input): array|WP_Error
{
    $email = nibwp_wcpro_email_object($input['email_id'] ?? '');
    if (is_wp_error($email)) {
        return $email;
    }
    if (!method_exists($email, 'update_option')) {
        return nibwp_wcpro_err('email_not_writable', sprintf('The email class serving "%s" has no update_option(), so its settings cannot be written.', (string) ($email->id ?? '')), 409);
    }

    $fields = (array) ($input['email'] ?? []);
    if ($fields === []) {
        return nibwp_wcpro_err('nothing_to_change', 'Give email:{…} with the keys to change: enabled, recipient, subject, heading, additional_content.', 422);
    }

    $changed = [];

    if (array_key_exists('enabled', $fields)) {
        $email->update_option('enabled', nibwp_wcpro_yesno($fields['enabled']));
        $changed['enabled'] = nibwp_wcpro_bool($fields['enabled']);
    }

    if (array_key_exists('recipient', $fields)) {
        if (nibwp_wcpro_email_is_customer($email)) {
            // Storing one would look like it worked: WooCommerce overwrites the
            // recipient from the order before every send, so the value would sit
            // in the option row forever and never be used.
            return nibwp_wcpro_err(
                'recipient_not_editable',
                sprintf('"%s" is a customer email — WooCommerce addresses it from the order, so a recipient set here would be stored and never used. Remove recipient from the request.', (string) ($email->id ?? '')),
                422,
            );
        }
        $recipients = nibwp_wcpro_email_recipient_list($fields['recipient']);
        if (is_wp_error($recipients)) {
            return $recipients;
        }
        $email->update_option('recipient', $recipients);
        $changed['recipient'] = $recipients;
    }

    foreach (['subject', 'heading', 'additional_content'] as $key) {
        if (!array_key_exists($key, $fields)) {
            continue;
        }
        // Kept as the owner typed it apart from tags: an email subject legitimately
        // holds {site_title} and {order_number}, and sanitize_text_field would not
        // mind those but wp_kses_post on a subject would encode the braces.
        $value = $key === 'additional_content'
            ? (function_exists('wp_kses_post') ? (string) wp_kses_post((string) $fields[$key]) : nibwp_wcpro_text($fields[$key]))
            : nibwp_wcpro_text($fields[$key], 500);
        $email->update_option($key, $value);
        $changed[$key] = $value;
    }

    if ($changed === []) {
        return nibwp_wcpro_err('nothing_to_change', 'None of the keys given are writable here. Use enabled, recipient, subject, heading or additional_content.', 422);
    }

    $steps = [];
    if (array_key_exists('subject', $changed) && trim((string) $changed['subject']) === '') {
        $steps[] = 'The subject was cleared, so WooCommerce will use its own default for this email rather than sending a blank subject.';
    }
    if (($changed['enabled'] ?? null) === false) {
        $steps[] = sprintf('This email is now switched off. %s', (string) (nibwp_wcpro_email_criticality()[(string) ($email->id ?? '')] ?? 'Nothing will be sent for this event.'));
    }

    return nibwp_wcpro_ok(
        'update_email',
        sprintf('Updated %s on the "%s" email.', implode(', ', array_keys($changed)), (string) ($email->id ?? '')),
        [
            'result' => ['id' => (string) ($email->id ?? ''), 'changed' => $changed],
            'next_steps' => $steps,
        ],
    );
}

/**
 * A comma-separated admin recipient list, every address validated.
 *
 * WooCommerce stores the list verbatim and silently drops what it cannot parse
 * at send time, so one typo in a three-address list quietly halves who is told
 * about an order.
 */
function nibwp_wcpro_email_recipient_list(mixed $value): string|WP_Error
{
    $raw = trim((string) ($value ?? ''));
    if ($raw === '') {
        return nibwp_wcpro_err('missing_recipient', 'An admin email needs at least one recipient address.', 422);
    }

    $clean = [];
    foreach (explode(',', $raw) as $part) {
        $address = trim($part);
        if ($address === '') {
            continue;
        }
        if (!nibwp_wcpro_is_email($address)) {
            return nibwp_wcpro_err('invalid_email', sprintf('"%s" is not a valid email address.', $address), 422);
        }
        $clean[] = $address;
    }

    if ($clean === []) {
        return nibwp_wcpro_err('missing_recipient', 'An admin email needs at least one recipient address.', 422);
    }

    return implode(', ', $clean);
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_update_email_settings(array $settings): array|WP_Error
{
    if ($settings === []) {
        return nibwp_wcpro_err('nothing_to_change', 'Give settings:{…} with the keys to change. action:"get_email_settings" lists them.', 422);
    }

    $map = nibwp_wcpro_email_settings_map();
    $changed = [];

    foreach ($settings as $key => $value) {
        $key = (string) $key;
        if (!isset($map[$key])) {
            return nibwp_wcpro_err(
                'unknown_setting',
                sprintf('"%s" is not a store-wide email setting. Valid keys: %s.', $key, implode(', ', array_keys($map))),
                422,
            );
        }

        switch ($key) {
            case 'from_address':
                $address = trim((string) $value);
                if (!nibwp_wcpro_is_email($address)) {
                    return nibwp_wcpro_err('invalid_email', sprintf('from_address must be a valid email address, got "%s".', $address), 422);
                }
                $clean = $address;
                break;

            case 'header_image':
                $raw = trim((string) $value);
                if ($raw === '') {
                    $clean = ''; // Clearing the header image is a legitimate change.
                    break;
                }
                $url = nibwp_wcpro_url($raw, 'header_image');
                if (is_wp_error($url)) {
                    return $url;
                }
                $clean = $url;
                break;

            case 'footer_text':
                // The footer legitimately holds a link and {site_title}, so it is
                // filtered as post content rather than flattened to plain text.
                $clean = function_exists('wp_kses_post') ? (string) wp_kses_post((string) $value) : nibwp_wcpro_text($value);
                break;

            case 'base_color':
            case 'background_color':
            case 'body_background_color':
            case 'text_color':
                $colour = nibwp_wcpro_email_color($value, $key);
                if (is_wp_error($colour)) {
                    return $colour;
                }
                $clean = $colour;
                break;

            default:
                $clean = nibwp_wcpro_text($value, 200);
                break;
        }

        update_option($map[$key], $clean);
        $changed[$key] = $clean;
    }

    return nibwp_wcpro_ok(
        'update_email_settings',
        sprintf('Updated %s.', implode(', ', array_keys($changed))),
        [
            'result' => ['changed' => $changed],
            'next_steps' => ['Send yourself one: action:"send_test_email" { email_id:"customer_completed_order", recipient:"<the owner\'s address>" } — it delivers real mail and shows the new styling.'],
        ],
    );
}

/**
 * A hex colour WooCommerce's email CSS will accept.
 *
 * Refused rather than normalised, because a colour that does not parse is
 * written into the inline CSS of every email the store sends and the result is
 * an unstyled message nobody notices until a customer forwards one.
 */
function nibwp_wcpro_email_color(mixed $value, string $field): string|WP_Error
{
    $raw = trim((string) ($value ?? ''));
    if ($raw === '') {
        return nibwp_wcpro_err('invalid_color', sprintf('%s cannot be empty — give a colour like "#7f54b3".', $field), 422);
    }
    if (!preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $raw)) {
        return nibwp_wcpro_err('invalid_color', sprintf('%s must be a hex colour such as "#7f54b3", got "%s".', $field, $raw), 422);
    }

    return strtolower($raw);
}

/* --------------------------------------------------------------- sending mail */

/**
 * Send a real email to a real address.
 *
 * THIS ACTION LEAVES THE BUILDING. Everything else in this skill writes to the
 * database, where a mistake can be read back and corrected; a delivered message
 * cannot be recalled. So:
 *
 *   - manage_woocommerce is asked for again HERE, at the send site, and not only
 *     at the hub door, because that is the check a future refactor of the
 *     dispatch would otherwise quietly drop;
 *   - the address is validated with is_email() before anything is composed;
 *   - the message is composed from the email's own subject, heading and
 *     additional content inside the store's template, and never from a real
 *     order. A test that rendered somebody's order would mail that customer's
 *     name, address and items to whatever address was asked for, which is a data
 *     leak with a friendly button on it.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_send_test_email(array $input): array|WP_Error
{
    $denied = nibwp_wcpro_require_manage('Sending a test email');
    if ($denied !== null) {
        return $denied;
    }

    $recipient = trim((string) ($input['recipient'] ?? ''));
    if ($recipient === '') {
        return nibwp_wcpro_err('missing_recipient', 'Provide recipient — the address the test email is delivered to. Ask the user for it; do not guess.', 422);
    }
    if (!nibwp_wcpro_is_email($recipient)) {
        return nibwp_wcpro_err('invalid_email', sprintf('"%s" is not a valid email address, so nothing was sent.', $recipient), 422);
    }

    $email = nibwp_wcpro_email_object($input['email_id'] ?? '');
    if (is_wp_error($email)) {
        return $email;
    }

    $id = (string) ($email->id ?? '');
    $title = (string) ($email->title ?? $id);
    $subject = nibwp_wcpro_email_stored($email, 'subject', 'get_default_subject');
    $heading = nibwp_wcpro_email_stored($email, 'heading', 'get_default_heading');
    $additional = method_exists($email, 'get_option') ? (string) $email->get_option('additional_content', '') : '';

    $body = implode('', [
        '<p>',
        sprintf(
            'This is a test of the <strong>%s</strong> notification, sent from %s. No order was involved and no customer was contacted.',
            function_exists('esc_html') ? esc_html($title) : $title,
            function_exists('esc_html') ? esc_html((string) get_option('blogname', '')) : (string) get_option('blogname', ''),
        ),
        '</p>',
        '<p>Subject line in force: <em>',
        function_exists('esc_html') ? esc_html($subject) : $subject,
        '</em></p>',
        $additional !== '' ? '<p>' . $additional . '</p>' : '',
        '<p>Placeholders that need an order — {order_number}, {order_date} — are left as written, because a test does not invent one.</p>',
    ]);

    $wc = function_exists('WC') ? WC() : null;
    $mailer = is_object($wc) && method_exists($wc, 'mailer') ? $wc->mailer() : null;
    if (!is_object($mailer) || !method_exists($mailer, 'wrap_message')) {
        return nibwp_wcpro_err('mailer_unavailable', 'WooCommerce\'s mailer is not available on this request, so nothing was sent.', 409);
    }
    if (!method_exists($email, 'send')) {
        return nibwp_wcpro_err('email_not_sendable', sprintf('The email class serving "%s" has no send(), so a test cannot be sent through it.', $id), 409);
    }

    $message = (string) $mailer->wrap_message(
        $heading !== '' ? $heading : $title,
        $body,
    );

    $headers = method_exists($email, 'get_headers') ? (string) $email->get_headers() : 'Content-Type: text/html' . "\r\n";
    $sent = (bool) $email->send(
        $recipient,
        sprintf('[Test] %s', $subject !== '' ? $subject : $title),
        $message,
        $headers,
        [],
    );

    return nibwp_wcpro_ok(
        'send_test_email',
        $sent
            ? sprintf('A test of the "%s" email was handed to the mail layer for %s.', $id, $recipient)
            : sprintf('The mail layer refused the test of "%s" for %s.', $id, $recipient),
        [
            'result' => [
                'email_id'  => $id,
                'recipient' => $recipient,
                'accepted'  => $sent,
                'subject'   => $subject,
                'heading'   => $heading,
                'from_name'    => (string) get_option('woocommerce_email_from_name', ''),
                'from_address' => (string) get_option('woocommerce_email_from_address', ''),
            ],
            'next_steps' => $sent
                ? ['"Accepted" means the server took the message, not that it arrived. Ask the user to check the inbox and the spam folder, and if nothing lands the problem is the site\'s mail transport rather than these settings.']
                : ['The mail layer refused it outright, which is a transport problem — an SMTP plugin misconfigured, or wp_mail() disabled — not an email setting. Report that rather than changing settings.'],
        ],
    );
}
