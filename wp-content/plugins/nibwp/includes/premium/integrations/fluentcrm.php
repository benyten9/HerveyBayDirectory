<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: FluentCRM.
 *
 * Replaces the nine actions this integration used to carry inside
 * plugin-integrations.php. That version could list contacts, read one, create,
 * update, move a tag, list tags, list campaigns and read one campaign's numbers
 * — and nothing else. No lists, no custom fields, no automations, no per-contact
 * email history, and no way to answer the question an owner actually asks, which
 * is "what in my CRM needs attention". All nine names still exist here and still
 * mean what they meant; the rest is new.
 *
 * Everything goes through FluentCRM's own internal API and Eloquent models —
 * FluentCrmApi('contacts') (app/Api/Classes/Contacts.php:23) and the models under
 * FluentCrm\App\Models — rather than its tables. fc_subscribers has grown columns
 * in most minor releases; reading through the models makes that FluentCRM's
 * problem rather than ours, and means the status transitions, the pivot writes and
 * the cleanup on delete fire the hooks the rest of the site is listening for.
 *
 * Verified against FluentCRM 2.9.x on disk.
 *
 * CAPABILITIES: FluentCRM does not gate its screens on manage_options. It defines
 * its own permission names and resolves them through
 * PermissionManager::currentUserCan() (app/Services/PermissionManager.php:301),
 * which short-circuits true for an administrator and otherwise checks a stored
 * per-manager list. Reading contacts is fcrm_read_contacts — the permission its
 * own contacts screen is gated on (app/Hooks/Handlers/AdminMenu.php:84) and the
 * one its own REST policy uses (app/Http/Policies/SubscriberPolicy.php:24).
 * Writing is fcrm_manage_contacts, deleting is fcrm_manage_contacts_delete,
 * tags and lists are fcrm_manage_contact_cats, emails are fcrm_read_emails and
 * automations are fcrm_read_funnels. Settling for manage_options would have
 * widened every one of those: a shop manager who was deliberately given read-only
 * CRM access would have got delete.
 *
 * TOKENS: a contact row carries `hash` (md5 of the email, fc_subscribers.hash),
 * and a contact has a `_secure_hash` and a managed hash in subscriber meta
 * (app/Functions/helpers.php:1652, app/Models/Subscriber.php:2825). Those are
 * bearer credentials: whoever holds one can unsubscribe that person, read their
 * preferences and rewrite their consent without logging in as anybody. The old
 * get_contact returned $contact->toArray(), which handed `hash` to every caller.
 * Nothing leaves here except through nibwp_fcrm_contact(), which is an ALLOW-list
 * of named columns — a denylist would have shipped the next token-shaped column a
 * FluentCRM release adds. A non-scalar is DROPPED rather than cast, because an
 * array is exactly how a token set travels under an innocent key.
 *
 * SECRETS: FluentCRM's own settings hold a sending-service configuration and its
 * webhooks are addressed by a secret key (app/Models/Webhook.php:20). None of
 * those values are returned anywhere. crm_audit reports them as a name and
 * whether they are set, under `withheld`.
 *
 * MAIL: there is no action here that sends a campaign, an email sequence, or any
 * message to a list or a segment, and there will not be. Mail to real people is
 * irreversible, it is the one thing in a CRM that cannot be walked back, and it
 * is not a decision that should arrive through a tool call. A test fails if the
 * enum ever grows one.
 *
 * REQUIRES: FluentCRM active. Email sequences additionally need the FluentCRM Pro
 * add-on (FluentCampaign); the one action that touches them says so rather than
 * pretending the site has none.
 */

/** Reading a contact. FluentCRM's own contacts screen is gated on this. */
const NIBWP_FCRM_READ_CONTACTS = 'fcrm_read_contacts';

/** Creating or changing a contact, and attaching tags or lists to one. */
const NIBWP_FCRM_MANAGE_CONTACTS = 'fcrm_manage_contacts';

/** Destroying a contact. FluentCRM separates this from manage on purpose. */
const NIBWP_FCRM_DELETE_CONTACTS = 'fcrm_manage_contacts_delete';

/** Creating or renaming a tag or a list. */
const NIBWP_FCRM_MANAGE_CATS = 'fcrm_manage_contact_cats';

/** Reading campaigns and their numbers. */
const NIBWP_FCRM_READ_EMAILS = 'fcrm_read_emails';

/** Reading automations. */
const NIBWP_FCRM_READ_FUNNELS = 'fcrm_read_funnels';

/** How many rows any listing on this hub will return, whatever was asked for. */
const NIBWP_FCRM_MAX_ROWS = 100;

/**
 * How many campaigns crm_audit will compute rates for.
 *
 * Campaign::stats() is four COUNT queries per campaign (Campaign.php:803), so an
 * unbounded audit on a site with 800 campaigns is a timeout, not a report.
 */
const NIBWP_FCRM_AUDIT_CAMPAIGNS = 25;

/**
 * Columns of a contact that may leave this file, and nothing else.
 *
 * Deliberately not including `hash`: that is the unsubscribe identifier, and it
 * is in the model's own $fillable (Subscriber.php:47) so it travels in every
 * toArray(). Deliberately an allow-list: fc_subscribers gained company_id,
 * total_points and avatar across 2.8 and 2.9, and a denylist written before those
 * existed would have passed them through without anybody deciding to.
 */
const NIBWP_FCRM_CONTACT_FIELDS = [
    'id',
    'prefix',
    'first_name',
    'last_name',
    'full_name',
    'email',
    'status',
    'contact_type',
    'source',
    'user_id',
    'company_id',
    'phone',
    'date_of_birth',
    'timezone',
    'address_line_1',
    'address_line_2',
    'postal_code',
    'city',
    'state',
    'country',
    'life_time_value',
    'total_points',
    'last_activity',
    'created_at',
    'updated_at',
];

/** A tag or a list. Both tables are the same four columns plus timestamps. */
const NIBWP_FCRM_TERM_FIELDS = ['id', 'title', 'slug', 'description', 'created_at', 'updated_at'];

/**
 * A campaign, minus its body and its settings blob.
 *
 * email_body is a rendered newsletter — tens of kilobytes of HTML that no caller
 * asked for — and `settings` is a serialised blob whose shape changes per release.
 */
const NIBWP_FCRM_CAMPAIGN_FIELDS = [
    'id',
    'parent_id',
    'type',
    'title',
    'slug',
    'status',
    'email_subject',
    'email_pre_header',
    'recipients_count',
    'design_template',
    'utm_campaign',
    'scheduled_at',
    'created_at',
    'updated_at',
];

/**
 * One queued or sent email, minus `email_hash`.
 *
 * email_hash addresses the open/click tracking endpoints for that exact send
 * (fc_campaign_emails.email_hash, database/migrations/CampaignEmails.php:41);
 * handing it out lets a holder forge engagement for somebody else's contact.
 */
const NIBWP_FCRM_EMAIL_FIELDS = [
    'id',
    'campaign_id',
    'subscriber_id',
    'email_type',
    'email_subject',
    'status',
    'is_open',
    'click_counter',
    'scheduled_at',
    'created_at',
    'updated_at',
];

/** An automation. conditions and settings are blobs and are dropped as such. */
const NIBWP_FCRM_FUNNEL_FIELDS = ['id', 'type', 'title', 'trigger_name', 'status', 'created_at', 'updated_at'];

/**
 * Which FluentCRM permissions each action needs. All of them, not any of them.
 *
 * Two entries carry two permissions because they answer across two surfaces:
 * campaign_recipients and automation_contacts both return contact records, so
 * reading them is reading contacts as well as reading emails or funnels. Gating
 * them on the campaign permission alone would have been a way to read the contact
 * list without the contact permission.
 */
const NIBWP_FCRM_ACTION_PERMISSIONS = [
    'list_contacts'         => [NIBWP_FCRM_READ_CONTACTS],
    'get_contact'           => [NIBWP_FCRM_READ_CONTACTS],
    'create_contact'        => [NIBWP_FCRM_MANAGE_CONTACTS],
    'update_contact'        => [NIBWP_FCRM_MANAGE_CONTACTS],
    'change_contact_status' => [NIBWP_FCRM_MANAGE_CONTACTS],
    'delete_contact'        => [NIBWP_FCRM_DELETE_CONTACTS],

    'list_tags'  => [NIBWP_FCRM_READ_CONTACTS],
    'create_tag' => [NIBWP_FCRM_MANAGE_CATS],
    'rename_tag' => [NIBWP_FCRM_MANAGE_CATS],
    'add_tag'    => [NIBWP_FCRM_MANAGE_CONTACTS],
    'remove_tag' => [NIBWP_FCRM_MANAGE_CONTACTS],

    'list_lists'       => [NIBWP_FCRM_READ_CONTACTS],
    'create_list'      => [NIBWP_FCRM_MANAGE_CATS],
    'rename_list'      => [NIBWP_FCRM_MANAGE_CATS],
    'add_to_list'      => [NIBWP_FCRM_MANAGE_CONTACTS],
    'remove_from_list' => [NIBWP_FCRM_MANAGE_CONTACTS],

    'list_custom_fields' => [NIBWP_FCRM_READ_CONTACTS],
    'set_custom_field'   => [NIBWP_FCRM_MANAGE_CONTACTS],

    'list_campaigns'      => [NIBWP_FCRM_READ_EMAILS],
    'get_campaign'        => [NIBWP_FCRM_READ_EMAILS],
    'get_campaign_stats'  => [NIBWP_FCRM_READ_EMAILS],
    'campaign_recipients' => [NIBWP_FCRM_READ_EMAILS, NIBWP_FCRM_READ_CONTACTS],

    'list_automations'    => [NIBWP_FCRM_READ_FUNNELS],
    'get_automation'      => [NIBWP_FCRM_READ_FUNNELS],
    'automation_contacts' => [NIBWP_FCRM_READ_FUNNELS, NIBWP_FCRM_READ_CONTACTS],

    'contact_emails' => [NIBWP_FCRM_READ_CONTACTS, NIBWP_FCRM_READ_EMAILS],

    'crm_audit' => [NIBWP_FCRM_READ_CONTACTS, NIBWP_FCRM_READ_EMAILS],
];

/** The one action that destroys data a person consented to. */
const NIBWP_FCRM_IRREVERSIBLE = ['delete_contact'];

/**
 * Compliance flags whose value may be reported.
 *
 * An allow-list rather than "every scalar in there", because this array is one
 * flat option that FluentCRM adds keys to (Helper::getComplianceSettings(),
 * Helper.php:2482). Copying every scalar out of it would mean the next key a
 * release adds travels by default, and the one thing a compliance screen is
 * likely to grow is a connection to whatever is doing the verifying.
 */
const NIBWP_FCRM_COMPLIANCE_FLAGS = [
    'anonymize_ip',
    'delete_contact_on_user',
    'personal_data_export',
    'one_click_unsubscribe',
    'enable_gravatar',
    'gravatar_fallback',
    'email_click_tracking',
    'email_open_tracking',
];

/**
 * Settings whose VALUE may be reported, because none of them is a credential.
 *
 * Everything else about FluentCRM's configuration — the sending-service
 * connection, SMTP, the webhook keys — is reported as a name and whether it is
 * set, and never as a value.
 */
const NIBWP_FCRM_SAFE_SETTINGS = ['from_name', 'from_email', 'emails_per_second'];

/**
 * Configuration whose value never leaves, under any action.
 *
 * Named so crm_audit can say "the sending service is configured" without saying
 * with what. The names are stable across FluentCRM releases; the shapes behind
 * them are not, which is a second reason not to try to summarise them.
 */
const NIBWP_FCRM_WITHHELD_SETTINGS = [
    'email_service_api_key'  => 'Sending-service API key',
    'smtp_password'          => 'SMTP password',
    'webhook_keys'           => 'Incoming webhook secret keys',
    'contact_secure_hashes'  => 'Per-contact unsubscribe and managed-preference tokens',
];

/** A FluentCRM model class name, or null when it is not loaded. */
function nibwp_fcrm_model(string $name): ?string
{
    $class = 'FluentCrm\\App\\Models\\' . $name;

    return class_exists($class) ? $class : null;
}

/**
 * Is FluentCRM actually here?
 *
 * FLUENTCRM is defined by the plugin's main file, and FluentCrmApi() is the
 * documented entry point every read below goes through. Between them they tell
 * "not installed" apart from "installed but half booted".
 */
function nibwp_fcrm_guard(): ?WP_Error
{
    if (!defined('FLUENTCRM') || !function_exists('FluentCrmApi') || nibwp_fcrm_model('Subscriber') === null) {
        return new WP_Error(
            'nibwp_fcrm_missing',
            __('FluentCRM is not active on this site.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/** A refusal about the request itself. */
function nibwp_fcrm_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error('nibwp_fcrm_' . $code, $message, ['status' => $status]);
}

/**
 * Does this account hold one FluentCRM permission?
 *
 * FluentCRM's permissions are not WordPress capabilities — since 2.8 they live in
 * CRM meta and are resolved by PermissionManager, which answers true for anybody
 * with manage_options and otherwise consults the stored manager list
 * (PermissionManager.php:301). Asking PermissionManager is the only way to get
 * the same answer FluentCRM's own screens get. The fallback matters on a site
 * where the class moved: it accepts the permission as a literal capability, which
 * is how FluentCRM stored these before 2.8, and otherwise falls back to
 * manage_options — never wider than that.
 */
function nibwp_fcrm_user_can(string $permission): bool
{
    $manager = 'FluentCrm\\App\\Services\\PermissionManager';

    if (class_exists($manager) && method_exists($manager, 'currentUserCan')) {
        try {
            return (bool) call_user_func([$manager, 'currentUserCan'], $permission);
        } catch (\Throwable $error) {
            // Fall through to the capability check rather than refusing: a broken
            // permission table should not make the CRM unreadable to its owner.
        }
    }

    return current_user_can($permission) || current_user_can('manage_options');
}

/** A refusal about authority rather than about the request. */
function nibwp_fcrm_forbidden(string $action, string $permission): WP_Error
{
    return new WP_Error(
        'nibwp_fcrm_forbidden',
        sprintf(
            /* translators: 1: action name, 2: FluentCRM permission name */
            __('%1$s needs the FluentCRM %2$s permission, which this account does not have.', 'nibwp'),
            $action,
            $permission
        ),
        ['status' => 403]
    );
}

/** Every permission an action needs, or null when the first one is missing. */
function nibwp_fcrm_require(string $action): ?WP_Error
{
    $needed = NIBWP_FCRM_ACTION_PERMISSIONS[$action] ?? null;

    if ($needed === null) {
        // An action with no entry is a wiring mistake, and defaulting it to
        // "allowed" is how an unlisted action gets handed out for free.
        return nibwp_fcrm_forbidden($action, NIBWP_FCRM_READ_CONTACTS);
    }

    foreach ($needed as $permission) {
        if (!nibwp_fcrm_user_can($permission)) {
            return nibwp_fcrm_forbidden($action, $permission);
        }
    }

    return null;
}

/** How many rows to return, clamped from input and never raisable past the cap. */
function nibwp_fcrm_rows(array $in, int $default = 20): int
{
    $asked = (int) ($in['per_page'] ?? $default);

    if ($asked < 1) {
        $asked = $default;
    }

    return min($asked, NIBWP_FCRM_MAX_ROWS);
}

/** Which page of a bounded listing. */
function nibwp_fcrm_page(array $in): int
{
    return max(1, (int) ($in['page'] ?? 1));
}

/**
 * A row's columns as a plain map, whatever kind of row it is.
 *
 * getAttributes() is preferred over toArray() on purpose: toArray() runs the
 * model's appended accessors, and Subscriber appends `photo`, which reaches
 * Gravatar. A shaper has no business making an HTTP call.
 */
function nibwp_fcrm_attributes(mixed $row): array
{
    if (is_array($row)) {
        return $row;
    }

    if (!is_object($row)) {
        return [];
    }

    if (method_exists($row, 'getAttributes')) {
        try {
            $attributes = $row->getAttributes();

            if (is_array($attributes)) {
                return $attributes;
            }
        } catch (\Throwable $error) {
            // A model mid-hydration can raise here; fall through to the cast.
        }
    }

    return (array) $row;
}

/**
 * Keep only the named fields, and only when they are scalar.
 *
 * The drop rather than a cast is the point. (string) on an array is "Array" plus
 * a notice, (string) on an object calls __toString, and a serialised blob under a
 * name like `settings` is precisely how a token set or a capability map would get
 * out of here under cover of a field somebody allowed years ago.
 */
function nibwp_fcrm_pick(mixed $row, array $allowed): array
{
    $attributes = nibwp_fcrm_attributes($row);
    $out = [];

    foreach ($allowed as $field) {
        if (!array_key_exists($field, $attributes)) {
            continue;
        }

        $value = $attributes[$field];

        if ($value === null) {
            $out[$field] = null;
            continue;
        }

        if (!is_scalar($value)) {
            continue;
        }

        $out[$field] = $value;
    }

    return $out;
}

/** A contact, through the allow-list, with a name a person can read. */
function nibwp_fcrm_contact(mixed $row): array
{
    $contact = nibwp_fcrm_pick($row, NIBWP_FCRM_CONTACT_FIELDS);

    if (!isset($contact['full_name'])) {
        $name = trim(((string) ($contact['first_name'] ?? '')) . ' ' . ((string) ($contact['last_name'] ?? '')));

        if ($name !== '') {
            $contact['full_name'] = $name;
        }
    }

    return $contact;
}

/** A tag or a list. */
function nibwp_fcrm_term(mixed $row): array
{
    return nibwp_fcrm_pick($row, NIBWP_FCRM_TERM_FIELDS);
}

/** A campaign. */
function nibwp_fcrm_campaign(mixed $row): array
{
    return nibwp_fcrm_pick($row, NIBWP_FCRM_CAMPAIGN_FIELDS);
}

/** One queued or sent email. */
function nibwp_fcrm_email(mixed $row): array
{
    return nibwp_fcrm_pick($row, NIBWP_FCRM_EMAIL_FIELDS);
}

/** An automation. */
function nibwp_fcrm_funnel(mixed $row): array
{
    return nibwp_fcrm_pick($row, NIBWP_FCRM_FUNNEL_FIELDS);
}

/** Map a model collection or array of rows through one shaper. */
function nibwp_fcrm_map(mixed $rows, callable $shaper): array
{
    if (is_object($rows) && method_exists($rows, 'all')) {
        try {
            $rows = $rows->all();
        } catch (\Throwable $error) {
            return [];
        }
    }

    if (!is_array($rows)) {
        return [];
    }

    return array_values(array_map($shaper, $rows));
}

/**
 * Custom-field values a contact may carry out.
 *
 * Only slugs FluentCRM has actually defined as custom fields, and never a key
 * beginning with an underscore. `_secure_hash` lives in the same subscriber-meta
 * table (helpers.php:1652) and is the one thing in there that must never travel;
 * a leading-underscore rule covers it and the internal keys a later release adds.
 */
function nibwp_fcrm_custom_values(mixed $contact): array
{
    if (!is_object($contact) || !method_exists($contact, 'custom_fields')) {
        return [];
    }

    try {
        $values = $contact->custom_fields();
    } catch (\Throwable $error) {
        return [];
    }

    if (!is_array($values)) {
        return [];
    }

    $out = [];

    foreach ($values as $key => $value) {
        $key = (string) $key;

        if ($key === '' || str_starts_with($key, '_')) {
            continue;
        }

        if ($value === null) {
            $out[$key] = null;
            continue;
        }

        if (!is_scalar($value)) {
            continue;
        }

        $out[$key] = $value;
    }

    return $out;
}

/** Defined custom contact fields, through FluentCRM's own API. */
function nibwp_fcrm_custom_field_defs(): array
{
    $fields = [];

    try {
        $fields = FluentCrmApi('contacts')->getCustomFields();
    } catch (\Throwable $error) {
        $fields = [];
    }

    if (!is_array($fields)) {
        return [];
    }

    $out = [];

    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }

        $out[] = [
            'slug'    => (string) ($field['slug'] ?? ''),
            'label'   => (string) ($field['label'] ?? ''),
            'type'    => (string) ($field['type'] ?? ''),
            'group'   => (string) ($field['group'] ?? ''),
            'options' => is_array($field['options'] ?? null) ? array_values(array_filter($field['options'], 'is_scalar')) : [],
        ];
    }

    return $out;
}

/**
 * Find the contact an action is about, by id or by email.
 *
 * Declared `object` rather than `object|WP_Error`: WP_Error is an object, so the
 * union is redundant and PHP rejects it at compile time. Callers check with
 * instanceof.
 */
function nibwp_fcrm_find_contact(array $in): object
{
    $api = FluentCrmApi('contacts');
    $contact = null;

    $id = (int) ($in['contact_id'] ?? 0);
    $email = trim((string) ($in['email'] ?? ''));

    if ($id > 0) {
        $contact = $api->getContact($id);
    } elseif ($email !== '') {
        $contact = $api->getContactByUserRef($email);
    } else {
        return nibwp_fcrm_err('no_contact_ref', __('Send contact_id or email to say which contact this is about.', 'nibwp'));
    }

    if (!$contact) {
        return nibwp_fcrm_err(
            'no_contact',
            __('No FluentCRM contact matches that contact_id or email.', 'nibwp'),
            404
        );
    }

    return $contact;
}

/** Tag or list ids from input, as a flat list of ids or titles. */
function nibwp_fcrm_term_refs(array $in, string $single, string $plural): array
{
    $refs = [];

    if (isset($in[$single]) && is_scalar($in[$single]) && (string) $in[$single] !== '') {
        $refs[] = $in[$single];
    }

    if (isset($in[$plural]) && is_array($in[$plural])) {
        foreach ($in[$plural] as $ref) {
            if (is_scalar($ref) && (string) $ref !== '') {
                $refs[] = $ref;
            }
        }
    }

    return array_values(array_unique($refs, SORT_REGULAR));
}

wp_register_ability('nibwp/fluentcrm-manage', [
    'label' => __('FluentCRM – Contacts, Lists & Campaigns', 'nibwp'),
    'description' => 'Run a FluentCRM install: search and edit contacts with their tags, lists, status and custom fields; create and rename tags and lists; read campaigns with their open, click, bounce and unsubscribe numbers; read automations and who is in them; and audit the CRM for stale pending contacts, unused tags, empty lists and campaigns with a deliverability problem. Reads and edits only — it never sends mail.',
    'category' => 'crm',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => [
                    // Contacts
                    'list_contacts', 'get_contact', 'create_contact', 'update_contact',
                    'change_contact_status', 'delete_contact',
                    // Tags
                    'list_tags', 'create_tag', 'rename_tag', 'add_tag', 'remove_tag',
                    // Lists
                    'list_lists', 'create_list', 'rename_list', 'add_to_list', 'remove_from_list',
                    // Custom contact fields
                    'list_custom_fields', 'set_custom_field',
                    // Campaigns
                    'list_campaigns', 'get_campaign', 'get_campaign_stats', 'campaign_recipients',
                    // Automations
                    'list_automations', 'get_automation', 'automation_contacts',
                    // Per-contact email history
                    'contact_emails',
                    // Audit
                    'crm_audit',
                ],
                'description' => 'The CRM operation to perform.',
            ],

            'contact_id' => ['type' => 'integer', 'description' => 'Contact ID.'],
            'email' => ['type' => 'string', 'description' => 'Contact email — an alternative to contact_id everywhere a contact is named.'],
            'contact_data' => [
                'type' => 'object',
                'description' => 'Fields for create_contact and update_contact. email is required to create.',
                'properties' => [
                    'email'          => ['type' => 'string'],
                    'prefix'         => ['type' => 'string'],
                    'first_name'     => ['type' => 'string'],
                    'last_name'      => ['type' => 'string'],
                    'status'         => ['type' => 'string', 'description' => 'subscribed, pending, unsubscribed, bounced, complained or spammed.'],
                    'contact_type'   => ['type' => 'string', 'description' => 'lead or customer.'],
                    'phone'          => ['type' => 'string'],
                    'date_of_birth'  => ['type' => 'string'],
                    'address_line_1' => ['type' => 'string'],
                    'address_line_2' => ['type' => 'string'],
                    'postal_code'    => ['type' => 'string'],
                    'city'           => ['type' => 'string'],
                    'state'          => ['type' => 'string'],
                    'country'        => ['type' => 'string'],
                    'timezone'       => ['type' => 'string'],
                    'source'         => ['type' => 'string'],
                    'tags'           => ['type' => 'array', 'items' => ['type' => ['integer', 'string']]],
                    'lists'          => ['type' => 'array', 'items' => ['type' => ['integer', 'string']]],
                ],
            ],

            'status' => ['type' => 'string', 'description' => 'Status to set with change_contact_status, or to filter a listing by.'],
            'contact_type' => ['type' => 'string', 'description' => 'Filter a contact listing by lead or customer.'],
            'search' => ['type' => 'string', 'description' => 'Free-text filter: email, name, phone, city, state, country, postal code.'],

            'tag_id' => ['type' => ['integer', 'string'], 'description' => 'Tag ID or title.'],
            'tag_ids' => ['type' => 'array', 'items' => ['type' => ['integer', 'string']], 'description' => 'Several tags at once.'],
            'list_id' => ['type' => ['integer', 'string'], 'description' => 'List ID or title.'],
            'list_ids' => ['type' => 'array', 'items' => ['type' => ['integer', 'string']], 'description' => 'Several lists at once.'],
            'title' => ['type' => 'string', 'description' => 'Title for create_tag, create_list, rename_tag and rename_list.'],
            'description' => ['type' => 'string', 'description' => 'Optional description for a new tag or list.'],

            'field' => ['type' => 'string', 'description' => 'Custom field slug for set_custom_field.'],
            'value' => ['type' => ['string', 'number', 'boolean', 'null'], 'description' => 'Value for set_custom_field. An empty string clears it.'],

            'campaign_id' => ['type' => 'integer', 'description' => 'Campaign ID.'],
            'funnel_id' => ['type' => 'integer', 'description' => 'Automation (funnel) ID.'],

            'days' => ['type' => 'integer', 'default' => 30, 'description' => 'Window in days for crm_audit. 1 to 365.'],
            'per_page' => ['type' => 'integer', 'default' => 20, 'description' => 'Rows per page. Clamped to 100 however high it is set.'],
            'page' => ['type' => 'integer', 'default' => 1],

            'confirm' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Required for delete_contact, which is irreversible.',
            ],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'contacts'    => ['type' => 'array'],
            'contact'     => ['type' => 'object'],
            'tags'        => ['type' => 'array'],
            'lists'       => ['type' => 'array'],
            'fields'      => ['type' => 'array'],
            'campaigns'   => ['type' => 'array'],
            'campaign'    => ['type' => 'object'],
            'stats'       => ['type' => 'object'],
            'automations' => ['type' => 'array'],
            'emails'      => ['type' => 'array'],
            'findings'    => ['type' => 'object'],
            'total'       => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => 'nibwp_fluentcrm_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Run a FluentCRM install: contacts, tags, lists, custom fields, campaigns and automations.',
                '',
                'WHAT THIS CANNOT DO — and will not grow:',
                '- It does not send a campaign, a sequence, or any email to a list or a segment.',
                '  Mail to real people cannot be recalled, and a tool call is not where that',
                '  decision belongs. Draft and schedule in FluentCRM, where the recipient count',
                '  is on screen before anything leaves.',
                '- It does not import or export contacts in bulk, and it does not merge or',
                '  deduplicate them.',
                '- It does not start, stop or re-run an automation against anybody. Reading which',
                '  contacts are in one is allowed; putting somebody into one is not.',
                '',
                'TOKENS AND SECRETS: a contact\'s unsubscribe hash, secure hash and managed',
                'preference link are never returned, at any nesting depth. Whoever holds one can',
                'rewrite that person\'s consent without logging in. FluentCRM\'s own sending-service',
                'key, SMTP password and webhook secrets are never returned either; crm_audit names',
                'them and says whether they are set, under `withheld`. Do not ask for them another',
                'way, and do not report their absence as a problem with this tool.',
                '',
                'CONSENT: delete_contact destroys the contact, their email history and the record',
                'of what they agreed to. It needs confirm=true and it cannot be undone. Changing a',
                'contact to unsubscribed through change_contact_status is the reversible answer and',
                'is almost always the one that was meant.',
                '',
                'BOUNDS: every listing is capped at 100 rows and the cap cannot be raised from',
                'input. Page through with `page` rather than asking for more.',
                '',
                'PERMISSIONS: FluentCRM has its own, separate from WordPress roles. Reading',
                'contacts needs fcrm_read_contacts, editing needs fcrm_manage_contacts, deleting',
                'needs fcrm_manage_contacts_delete, tags and lists need fcrm_manage_contact_cats,',
                'campaigns need fcrm_read_emails and automations need fcrm_read_funnels. A refusal',
                'names the one that is missing — it is a permission to grant in FluentCRM, not a',
                'reason to retry.',
                '',
                'START WITH crm_audit. It answers what needs attention: contacts stuck pending',
                'past the window, tags nothing uses, lists with nobody on them, and campaigns',
                'whose bounce or unsubscribe rate is high enough to put the sending domain at',
                'risk.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_fluentcrm_manage(array $input): array|WP_Error
{
    // Order matters, and each of these answers with its own code so a test can
    // tell which one spoke. The plugin check is first: without FluentCRM loaded
    // there is no PermissionManager to ask, and a capability refusal would be a
    // lie about why the call failed.
    if ($guard = nibwp_fcrm_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');

    if (!array_key_exists($action, NIBWP_FCRM_ACTION_PERMISSIONS)) {
        return nibwp_fcrm_err('unknown_action', sprintf(
            /* translators: %s: the action that was requested */
            __('Unknown FluentCRM action: %s', 'nibwp'),
            $action
        ));
    }

    if ($denied = nibwp_fcrm_require($action)) {
        return $denied;
    }

    if (in_array($action, NIBWP_FCRM_IRREVERSIBLE, true) && empty($input['confirm'])) {
        return nibwp_fcrm_err(
            'unconfirmed',
            __('This destroys the contact, their email history and their consent record, and cannot be undone. Re-issue with confirm set to true if that is intended.', 'nibwp')
        );
    }

    try {
        return nibwp_fcrm_dispatch($action, $input);
    } catch (\Throwable $error) {
        // FluentCRM's models throw rather than return null — a missing record, a
        // duplicate email, a custom field that is not defined. An uncaught Error
        // inside an ability takes the whole REST request with it, so the reason
        // comes back as a refusal instead.
        return nibwp_fcrm_err('failed', sprintf(
            /* translators: 1: action name, 2: error message from FluentCRM */
            __('FluentCRM could not complete %1$s: %2$s', 'nibwp'),
            $action,
            $error->getMessage()
        ));
    }
}

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_fcrm_dispatch(string $action, array $in): array|WP_Error
{
    switch ($action) {
        case 'list_contacts':
        case 'get_contact':
        case 'create_contact':
        case 'update_contact':
        case 'change_contact_status':
        case 'delete_contact':
            return nibwp_fcrm_contacts($action, $in);

        case 'list_tags':
        case 'create_tag':
        case 'rename_tag':
        case 'add_tag':
        case 'remove_tag':
        case 'list_lists':
        case 'create_list':
        case 'rename_list':
        case 'add_to_list':
        case 'remove_from_list':
            return nibwp_fcrm_terms($action, $in);

        case 'list_custom_fields':
        case 'set_custom_field':
            return nibwp_fcrm_custom_fields($action, $in);

        case 'list_campaigns':
        case 'get_campaign':
        case 'get_campaign_stats':
        case 'campaign_recipients':
            return nibwp_fcrm_campaigns($action, $in);

        case 'list_automations':
        case 'get_automation':
        case 'automation_contacts':
            return nibwp_fcrm_automations($action, $in);

        case 'contact_emails':
            return nibwp_fcrm_contact_emails($in);

        case 'crm_audit':
            return nibwp_fcrm_audit($in);
    }

    // Unreachable through nibwp_fluentcrm_manage(), which validates the action
    // against the permission map first. Kept so a direct caller gets an answer
    // rather than a null.
    return nibwp_fcrm_err('unknown_action', sprintf(
        /* translators: %s: the action that was requested */
        __('Unknown FluentCRM action: %s', 'nibwp'),
        $action
    ));
}

/** @return array<string, mixed>|WP_Error */
function nibwp_fcrm_contacts(string $action, array $in): array|WP_Error
{
    $model = nibwp_fcrm_model('Subscriber');

    if ($model === null) {
        return nibwp_fcrm_err('no_model', __('FluentCRM contacts are unavailable on this version.', 'nibwp'));
    }

    $api = FluentCrmApi('contacts');

    if ($action === 'list_contacts') {
        $per_page = nibwp_fcrm_rows($in);
        $offset = (nibwp_fcrm_page($in) - 1) * $per_page;

        $query = $model::query();

        $search = trim((string) ($in['search'] ?? ''));
        if ($search !== '') {
            $query->searchBy($search);
        }

        $status = trim((string) ($in['status'] ?? ''));
        if ($status !== '') {
            $query->filterByStatues([$status]);
        }

        $contact_type = trim((string) ($in['contact_type'] ?? ''));
        if ($contact_type !== '') {
            $query->filterByContactType($contact_type);
        }

        $tags = nibwp_fcrm_term_refs($in, 'tag_id', 'tag_ids');
        if ($tags !== []) {
            // FluentCRM's own scope resolves a title as readily as an id, so a
            // caller who has the tag name does not need a lookup round trip.
            $query->filterByTags($tags, nibwp_fcrm_refs_are_ids($tags) ? 'id' : 'title');
        }

        $lists = nibwp_fcrm_term_refs($in, 'list_id', 'list_ids');
        if ($lists !== []) {
            $query->filterByLists($lists, nibwp_fcrm_refs_are_ids($lists) ? 'id' : 'title');
        }

        $total = (int) (clone $query)->count();
        $rows = (clone $query)->orderBy('id', 'DESC')->limit($per_page)->offset($offset)->get();

        return [
            'contacts' => nibwp_fcrm_map($rows, 'nibwp_fcrm_contact'),
            'total'    => $total,
            'per_page' => $per_page,
            'page'     => nibwp_fcrm_page($in),
        ];
    }

    if ($action === 'create_contact') {
        $data = is_array($in['contact_data'] ?? null) ? $in['contact_data'] : [];
        $email = trim((string) ($data['email'] ?? ''));

        if ($email === '' || !is_email($email)) {
            return nibwp_fcrm_err('bad_email', __('create_contact needs contact_data.email, and it has to be a valid address.', 'nibwp'));
        }

        $payload = nibwp_fcrm_writable($data);
        $contact = $api->createOrUpdate($payload);

        if (!$contact) {
            return nibwp_fcrm_err('create_failed', __('FluentCRM did not create the contact and did not say why.', 'nibwp'));
        }

        nibwp_fcrm_attach_from_data($contact, $data);

        return [
            'created' => true,
            'contact' => nibwp_fcrm_contact_detail($contact),
        ];
    }

    $contact = nibwp_fcrm_find_contact($in);

    if ($contact instanceof WP_Error) {
        return $contact;
    }

    if ($action === 'get_contact') {
        return ['contact' => nibwp_fcrm_contact_detail($contact)];
    }

    if ($action === 'update_contact') {
        $data = is_array($in['contact_data'] ?? null) ? $in['contact_data'] : [];
        $payload = nibwp_fcrm_writable($data);

        if ($payload === [] && !isset($data['tags']) && !isset($data['lists'])) {
            return nibwp_fcrm_err('nothing_to_do', __('Nothing to update — send contact_data with at least one field, or tags, or lists.', 'nibwp'));
        }

        if (isset($payload['email']) && !is_email((string) $payload['email'])) {
            return nibwp_fcrm_err('bad_email', __('That is not a valid email address.', 'nibwp'));
        }

        if ($payload !== []) {
            $contact->fill($payload);
            $contact->save();
        }

        nibwp_fcrm_attach_from_data($contact, $data);

        return [
            'updated' => true,
            'contact' => nibwp_fcrm_contact_detail($contact),
        ];
    }

    if ($action === 'change_contact_status') {
        $status = trim((string) ($in['status'] ?? ''));
        $allowed = ['subscribed', 'pending', 'unsubscribed', 'bounced', 'complained', 'spammed'];

        if (!in_array($status, $allowed, true)) {
            return nibwp_fcrm_err('bad_status', sprintf(
                /* translators: %s: comma-separated list of allowed statuses */
                __('Contact status must be one of: %s', 'nibwp'),
                implode(', ', $allowed)
            ));
        }

        $was = (string) ($contact->status ?? '');

        if ($was === $status) {
            return [
                'updated' => false,
                'contact' => nibwp_fcrm_contact_detail($contact),
                'note'    => __('Already in that status; nothing changed.', 'nibwp'),
            ];
        }

        // updateStatus() rather than a bare save: it fires the status-changed
        // hooks (Subscriber.php:2836), which is what keeps automations, double
        // opt-in and the unsubscribe reporting consistent with the new value.
        $contact->updateStatus($status);

        return [
            'updated' => true,
            'was'     => $was,
            'contact' => nibwp_fcrm_contact_detail($contact),
        ];
    }

    // delete_contact — confirm-gated in nibwp_fluentcrm_manage(). This destroys
    // the contact row and, through FluentCRM's own cleanup hooks, their email
    // history, their tags and lists, and the record of the consent they gave.
    // There is no undo and no export first.
    $id = (int) ($contact->id ?? 0);
    $email = (string) ($contact->email ?? '');

    $helper = 'FluentCrm\\App\\Services\\Helper';

    if (class_exists($helper) && method_exists($helper, 'deleteContacts')) {
        // Helper::deleteContacts() is the path FluentCRM's own screens use
        // (SubscriberController.php:261); it fires the before/after hooks that
        // the related-row cleanup is attached to. Calling $contact->delete()
        // would leave orphaned campaign emails and pivot rows behind.
        call_user_func([$helper, 'deleteContacts'], [$id]);
    } else {
        $contact->delete();
    }

    return [
        'deleted'    => true,
        'contact_id' => $id,
        'email'      => $email,
        'note'       => __('The contact, their email history and their consent record are gone. If they subscribe again they start as a new contact with no history.', 'nibwp'),
    ];
}

/** Are these refs all numeric ids, or are they titles? */
function nibwp_fcrm_refs_are_ids(array $refs): bool
{
    foreach ($refs as $ref) {
        if (!is_numeric($ref)) {
            return false;
        }
    }

    return true;
}

/**
 * The contact columns a caller may write.
 *
 * The same allow-list idea as the read side, for the opposite reason: `hash` is
 * in Subscriber::$fillable, so a caller who could pass arbitrary keys through
 * fill() could set a contact's unsubscribe identifier to one they already know.
 */
function nibwp_fcrm_writable(array $data): array
{
    $writable = [
        'email', 'prefix', 'first_name', 'last_name', 'status', 'contact_type',
        'phone', 'date_of_birth', 'timezone', 'address_line_1', 'address_line_2',
        'postal_code', 'city', 'state', 'country', 'source',
    ];

    $out = [];

    foreach ($writable as $field) {
        if (!array_key_exists($field, $data)) {
            continue;
        }

        $value = $data[$field];

        if ($value === null || is_scalar($value)) {
            $out[$field] = $value === null ? null : (string) $value;
        }
    }

    return $out;
}

/** Attach whatever tags and lists came in with a create or an update. */
function nibwp_fcrm_attach_from_data(object $contact, array $data): void
{
    if (!empty($data['tags']) && is_array($data['tags']) && method_exists($contact, 'attachTags')) {
        $contact->attachTags(array_values(array_filter($data['tags'], 'is_scalar')));
    }

    if (!empty($data['lists']) && is_array($data['lists']) && method_exists($contact, 'attachLists')) {
        $contact->attachLists(array_values(array_filter($data['lists'], 'is_scalar')));
    }
}

/**
 * A contact with everything an agent needs to decide something about them.
 *
 * Each relation is read in its own try/catch: a site with a half-migrated pivot
 * table should still return the contact rather than refusing the whole call
 * because the tag join failed.
 */
function nibwp_fcrm_contact_detail(object $contact): array
{
    $detail = nibwp_fcrm_contact($contact);

    $detail['tags'] = [];
    $detail['lists'] = [];

    try {
        $detail['tags'] = nibwp_fcrm_map($contact->tags, 'nibwp_fcrm_term');
    } catch (\Throwable $error) {
        $detail['tags'] = [];
    }

    try {
        $detail['lists'] = nibwp_fcrm_map($contact->lists, 'nibwp_fcrm_term');
    } catch (\Throwable $error) {
        $detail['lists'] = [];
    }

    $detail['custom_fields'] = nibwp_fcrm_custom_values($contact);

    try {
        $stats = method_exists($contact, 'stats') ? $contact->stats() : [];
        $detail['email_stats'] = [
            'sent'   => (int) ($stats['emails'] ?? 0),
            'opens'  => (int) ($stats['opens'] ?? 0),
            'clicks' => (int) ($stats['clicks'] ?? 0),
        ];
    } catch (\Throwable $error) {
        $detail['email_stats'] = null;
    }

    return $detail;
}

/** @return array<string, mixed>|WP_Error */
function nibwp_fcrm_terms(string $action, array $in): array|WP_Error
{
    $is_list = in_array($action, ['list_lists', 'create_list', 'rename_list', 'add_to_list', 'remove_from_list'], true);
    $model = nibwp_fcrm_model($is_list ? 'Lists' : 'Tag');

    if ($model === null) {
        return nibwp_fcrm_err('no_model', __('FluentCRM tags and lists are unavailable on this version.', 'nibwp'));
    }

    $key = $is_list ? 'lists' : 'tags';

    if ($action === 'list_tags' || $action === 'list_lists') {
        $per_page = nibwp_fcrm_rows($in);
        $offset = (nibwp_fcrm_page($in) - 1) * $per_page;

        $query = $model::query();
        $search = trim((string) ($in['search'] ?? ''));

        if ($search !== '') {
            $query->searchBy($search);
        }

        $total = (int) (clone $query)->count();
        $rows = (clone $query)->orderBy('title', 'ASC')->limit($per_page)->offset($offset)->get();

        $out = [];

        foreach (nibwp_fcrm_rowset($rows) as $row) {
            $term = nibwp_fcrm_term($row);

            // The count is the whole reason an owner opens this screen: a tag
            // with 4000 contacts and a tag with none look identical otherwise.
            try {
                $term['contacts'] = method_exists($row, 'totalCount') ? (int) $row->totalCount() : null;
                $term['subscribed'] = method_exists($row, 'countByStatus') ? (int) $row->countByStatus('subscribed') : null;
            } catch (\Throwable $error) {
                $term['contacts'] = null;
                $term['subscribed'] = null;
            }

            $out[] = $term;
        }

        return [
            $key       => $out,
            'total'    => $total,
            'per_page' => $per_page,
            'page'     => nibwp_fcrm_page($in),
        ];
    }

    if ($action === 'create_tag' || $action === 'create_list') {
        $title = trim((string) ($in['title'] ?? ''));

        if ($title === '') {
            return nibwp_fcrm_err('no_title', __('Send a title for the new tag or list.', 'nibwp'));
        }

        if ($model::query()->where('title', $title)->first()) {
            return nibwp_fcrm_err('duplicate_title', __('One already exists with that title.', 'nibwp'), 409);
        }

        $row = $model::query()->create([
            'title'       => $title,
            'slug'        => sanitize_title($title),
            'description' => (string) ($in['description'] ?? ''),
        ]);

        return ['created' => true, ($is_list ? 'list' : 'tag') => nibwp_fcrm_term($row)];
    }

    if ($action === 'rename_tag' || $action === 'rename_list') {
        $ref = $in[$is_list ? 'list_id' : 'tag_id'] ?? null;
        $row = nibwp_fcrm_find_term($model, $ref);

        if ($row instanceof WP_Error) {
            return $row;
        }

        $title = trim((string) ($in['title'] ?? ''));

        if ($title === '') {
            return nibwp_fcrm_err('no_title', __('Send the new title.', 'nibwp'));
        }

        $was = (string) ($row->title ?? '');
        $row->title = $title;

        if (isset($in['description'])) {
            $row->description = (string) $in['description'];
        }

        $row->save();

        return [
            'updated' => true,
            'was'     => $was,
            ($is_list ? 'list' : 'tag') => nibwp_fcrm_term($row),
            'note'    => __('Renamed in place, so every contact keeps it. The slug is unchanged, which matters if a form or an automation refers to it by slug.', 'nibwp'),
        ];
    }

    // The four attach/detach actions. All of them are about one contact.
    $contact = nibwp_fcrm_find_contact($in);

    if ($contact instanceof WP_Error) {
        return $contact;
    }

    $refs = $is_list
        ? nibwp_fcrm_term_refs($in, 'list_id', 'list_ids')
        : nibwp_fcrm_term_refs($in, 'tag_id', 'tag_ids');

    if ($refs === []) {
        return nibwp_fcrm_err('no_term_ref', $is_list
            ? __('Send list_id or list_ids.', 'nibwp')
            : __('Send tag_id or tag_ids.', 'nibwp'));
    }

    // Resolve titles to ids before handing them over: attachTags() takes ids, and
    // a title passed through silently attaches nothing.
    $ids = [];

    foreach ($refs as $ref) {
        $row = nibwp_fcrm_find_term($model, $ref);

        if ($row instanceof WP_Error) {
            return $row;
        }

        $ids[] = (int) $row->id;
    }

    if ($action === 'add_tag') {
        $contact->attachTags($ids);
    } elseif ($action === 'remove_tag') {
        $contact->detachTags($ids);
    } elseif ($action === 'add_to_list') {
        $contact->attachLists($ids);
    } else {
        $contact->detachLists($ids);
    }

    return [
        'updated' => true,
        'contact' => nibwp_fcrm_contact_detail($contact),
    ];
}

/**
 * Find a tag or a list by id or by title.
 *
 * Declared `object` for the same reason as nibwp_fcrm_find_contact().
 */
function nibwp_fcrm_find_term(string $model, mixed $ref): object
{
    if (!is_scalar($ref) || (string) $ref === '') {
        return nibwp_fcrm_err('no_term_ref', __('Send the tag or list as an ID or a title.', 'nibwp'));
    }

    $row = is_numeric($ref)
        ? $model::query()->find((int) $ref)
        : $model::query()->where('title', (string) $ref)->first();

    if (!$row) {
        return nibwp_fcrm_err('no_term', __('No FluentCRM tag or list matches that ID or title.', 'nibwp'), 404);
    }

    return $row;
}

/** A model collection, an array, or neither, as a plain array of rows. */
function nibwp_fcrm_rowset(mixed $rows): array
{
    if (is_object($rows) && method_exists($rows, 'all')) {
        try {
            $rows = $rows->all();
        } catch (\Throwable $error) {
            return [];
        }
    }

    return is_array($rows) ? array_values($rows) : [];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_fcrm_custom_fields(string $action, array $in): array|WP_Error
{
    $defs = nibwp_fcrm_custom_field_defs();

    if ($action === 'list_custom_fields') {
        return [
            'fields' => array_slice($defs, 0, NIBWP_FCRM_MAX_ROWS),
            'total'  => count($defs),
            'note'   => __('These are the contact custom fields FluentCRM has defined. set_custom_field only accepts a slug from this list.', 'nibwp'),
        ];
    }

    // set_custom_field
    $field = trim((string) ($in['field'] ?? ''));

    if ($field === '') {
        return nibwp_fcrm_err('no_field', __('set_custom_field needs a field slug. Call list_custom_fields to see them.', 'nibwp'));
    }

    $slugs = array_column($defs, 'slug');

    if (!in_array($field, $slugs, true)) {
        return nibwp_fcrm_err('unknown_field', sprintf(
            /* translators: %s: the custom field slug that was requested */
            __('FluentCRM has no contact custom field with the slug %s. Fields are defined in FluentCRM, not here.', 'nibwp'),
            $field
        ), 404);
    }

    $value = $in['value'] ?? null;

    if ($value !== null && !is_scalar($value)) {
        return nibwp_fcrm_err('bad_value', __('A custom field value has to be a string, a number or a boolean.', 'nibwp'));
    }

    $contact = nibwp_fcrm_find_contact($in);

    if ($contact instanceof WP_Error) {
        return $contact;
    }

    // syncCustomFieldValues() sanitises against the field's own definition and
    // fires the update hooks (Subscriber.php:645). Writing the meta row directly
    // would skip both, and a date or a select would end up holding a value
    // FluentCRM's own UI cannot render.
    $contact->syncCustomFieldValues([$field => $value === null ? '' : (string) $value], false);

    return [
        'updated'       => true,
        'field'         => $field,
        'contact_id'    => (int) ($contact->id ?? 0),
        'custom_fields' => nibwp_fcrm_custom_values($contact),
    ];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_fcrm_campaigns(string $action, array $in): array|WP_Error
{
    $model = nibwp_fcrm_model('Campaign');

    if ($model === null) {
        return nibwp_fcrm_err('no_model', __('FluentCRM campaigns are unavailable on this version.', 'nibwp'));
    }

    if ($action === 'list_campaigns') {
        $per_page = nibwp_fcrm_rows($in);
        $offset = (nibwp_fcrm_page($in) - 1) * $per_page;

        $query = $model::query();
        $status = trim((string) ($in['status'] ?? ''));

        if ($status !== '') {
            $query->where('status', $status);
        }

        $total = (int) (clone $query)->count();
        $rows = (clone $query)->orderBy('id', 'DESC')->limit($per_page)->offset($offset)->get();

        return [
            'campaigns' => nibwp_fcrm_map($rows, 'nibwp_fcrm_campaign'),
            'total'     => $total,
            'per_page'  => $per_page,
            'page'      => nibwp_fcrm_page($in),
        ];
    }

    $id = (int) ($in['campaign_id'] ?? 0);
    $campaign = $id > 0 ? $model::query()->find($id) : null;

    if (!$campaign) {
        return nibwp_fcrm_err('no_campaign', __('No FluentCRM campaign with that ID.', 'nibwp'), 404);
    }

    if ($action === 'get_campaign_stats') {
        // The legacy shape, kept exactly: `total`, `sent`, `opens`, `clicks` and
        // `unsubscribes` are what callers already ask for by name, and FluentCRM
        // calls two of them something else internally.
        return ['stats' => nibwp_fcrm_campaign_stats($campaign)];
    }

    if ($action === 'get_campaign') {
        $detail = nibwp_fcrm_campaign($campaign);
        $detail['stats'] = nibwp_fcrm_campaign_stats($campaign);
        $detail['recipients'] = nibwp_fcrm_campaign_recipient_counts((int) $campaign->id);

        return ['campaign' => $detail];
    }

    // campaign_recipients — who actually got it, bounded, with the per-status
    // summary alongside so a caller does not have to page through 40,000 rows to
    // learn that 300 bounced.
    $emails = nibwp_fcrm_model('CampaignEmail');

    if ($emails === null) {
        return nibwp_fcrm_err('no_model', __('FluentCRM campaign emails are unavailable on this version.', 'nibwp'));
    }

    $per_page = nibwp_fcrm_rows($in);
    $offset = (nibwp_fcrm_page($in) - 1) * $per_page;

    $query = $emails::query()->where('campaign_id', (int) $campaign->id);
    $status = trim((string) ($in['status'] ?? ''));

    if ($status !== '') {
        $query->where('status', $status);
    }

    $rows = (clone $query)->orderBy('id', 'DESC')->limit($per_page)->offset($offset)->get();

    return [
        'campaign_id' => (int) $campaign->id,
        'summary'     => nibwp_fcrm_campaign_recipient_counts((int) $campaign->id),
        'emails'      => nibwp_fcrm_map($rows, 'nibwp_fcrm_email'),
        'total'       => (int) (clone $query)->count(),
        'per_page'    => $per_page,
        'page'        => nibwp_fcrm_page($in),
    ];
}

/**
 * One campaign's numbers, in the vocabulary the old action used.
 *
 * FluentCRM's Campaign::stats() returns `views` and `unsubscribers`
 * (Campaign.php:803) and also honours anonymous tracking, which a hand-rolled
 * COUNT would get wrong on a site that turned identified tracking off. Going
 * through it and renaming two keys is the honest version of both.
 */
function nibwp_fcrm_campaign_stats(object $campaign): array
{
    $stats = [];

    try {
        $stats = method_exists($campaign, 'stats') ? $campaign->stats() : [];
    } catch (\Throwable $error) {
        $stats = [];
    }

    if (!is_array($stats)) {
        $stats = [];
    }

    $sent = (int) ($stats['sent'] ?? 0);
    $bounced = nibwp_fcrm_campaign_email_count((int) ($campaign->id ?? 0), 'bounced');
    $unsubscribes = (int) ($stats['unsubscribers'] ?? 0);

    return [
        'id'                => (int) ($campaign->id ?? 0),
        'title'             => (string) ($campaign->title ?? ''),
        'status'            => (string) ($campaign->status ?? ''),
        'total'             => (int) ($stats['total'] ?? 0),
        'sent'              => $sent,
        'opens'             => (int) ($stats['views'] ?? 0),
        'clicks'            => (int) ($stats['clicks'] ?? 0),
        'unsubscribes'      => $unsubscribes,
        'bounced'           => $bounced,
        'open_rate'         => nibwp_fcrm_rate((int) ($stats['views'] ?? 0), $sent),
        'click_rate'        => nibwp_fcrm_rate((int) ($stats['clicks'] ?? 0), $sent),
        'unsubscribe_rate'  => nibwp_fcrm_rate($unsubscribes, $sent),
        'bounce_rate'       => nibwp_fcrm_rate($bounced, $sent),
    ];
}

/** A percentage to one decimal, or null when there is no denominator. */
function nibwp_fcrm_rate(int $part, int $whole): ?float
{
    if ($whole <= 0) {
        return null;
    }

    return round(($part / $whole) * 100, 1);
}

/** How many of a campaign's emails are in one status. */
function nibwp_fcrm_campaign_email_count(int $campaign_id, string $status): int
{
    $emails = nibwp_fcrm_model('CampaignEmail');

    if ($emails === null || $campaign_id <= 0) {
        return 0;
    }

    try {
        return (int) $emails::query()->where('campaign_id', $campaign_id)->where('status', $status)->count();
    } catch (\Throwable $error) {
        return 0;
    }
}

/** The per-status recipient summary for one campaign. */
function nibwp_fcrm_campaign_recipient_counts(int $campaign_id): array
{
    $out = [];

    foreach (['sent', 'scheduled', 'pending', 'failed', 'bounced', 'draft'] as $status) {
        $out[$status] = nibwp_fcrm_campaign_email_count($campaign_id, $status);
    }

    return $out;
}

/** @return array<string, mixed>|WP_Error */
function nibwp_fcrm_automations(string $action, array $in): array|WP_Error
{
    $model = nibwp_fcrm_model('Funnel');

    if ($model === null) {
        return nibwp_fcrm_err('no_model', __('FluentCRM automations are unavailable on this version.', 'nibwp'));
    }

    if ($action === 'list_automations') {
        $per_page = nibwp_fcrm_rows($in);
        $offset = (nibwp_fcrm_page($in) - 1) * $per_page;

        $query = $model::query();
        $status = trim((string) ($in['status'] ?? ''));

        if ($status !== '') {
            $query->where('status', $status);
        }

        $total = (int) (clone $query)->count();
        $rows = (clone $query)->orderBy('id', 'DESC')->limit($per_page)->offset($offset)->get();

        $out = [];

        foreach (nibwp_fcrm_rowset($rows) as $row) {
            $funnel = nibwp_fcrm_funnel($row);

            try {
                $funnel['contacts'] = method_exists($row, 'getSubscribersCount') ? (int) $row->getSubscribersCount() : null;
            } catch (\Throwable $error) {
                $funnel['contacts'] = null;
            }

            $out[] = $funnel;
        }

        return [
            'automations' => $out,
            'total'       => $total,
            'per_page'    => $per_page,
            'page'        => nibwp_fcrm_page($in),
        ];
    }

    $id = (int) ($in['funnel_id'] ?? 0);
    $funnel = $id > 0 ? $model::query()->find($id) : null;

    if (!$funnel) {
        return nibwp_fcrm_err('no_automation', __('No FluentCRM automation with that ID.', 'nibwp'), 404);
    }

    if ($action === 'get_automation') {
        $detail = nibwp_fcrm_funnel($funnel);

        try {
            $detail['contacts'] = (int) $funnel->getSubscribersCount();
        } catch (\Throwable $error) {
            $detail['contacts'] = null;
        }

        // The step list, as names and types only. A step's `settings` is where an
        // automation keeps API payloads for whatever it integrates with, so it
        // goes nowhere near a response.
        $detail['steps'] = [];

        try {
            foreach (nibwp_fcrm_rowset($funnel->actions) as $step) {
                $detail['steps'][] = nibwp_fcrm_pick($step, ['id', 'action_name', 'type', 'sequence', 'status', 'title', 'delay']);
            }
        } catch (\Throwable $error) {
            $detail['steps'] = [];
        }

        return ['automation' => $detail];
    }

    // automation_contacts — who is in it, and where they got to.
    $tracker = nibwp_fcrm_model('FunnelSubscriber');

    if ($tracker === null) {
        return nibwp_fcrm_err('no_model', __('FluentCRM automation membership is unavailable on this version.', 'nibwp'));
    }

    $per_page = nibwp_fcrm_rows($in);
    $offset = (nibwp_fcrm_page($in) - 1) * $per_page;

    $query = $tracker::query()->where('funnel_id', (int) $funnel->id);
    $status = trim((string) ($in['status'] ?? ''));

    if ($status !== '') {
        $query->where('status', $status);
    }

    $rows = (clone $query)->orderBy('id', 'DESC')->limit($per_page)->offset($offset)->get();
    $out = [];

    foreach (nibwp_fcrm_rowset($rows) as $row) {
        $entry = nibwp_fcrm_pick($row, ['id', 'subscriber_id', 'status', 'source_trigger_name', 'last_sequence_id', 'created_at', 'updated_at']);

        try {
            $entry['contact'] = $row->subscriber ? nibwp_fcrm_contact($row->subscriber) : null;
        } catch (\Throwable $error) {
            $entry['contact'] = null;
        }

        $out[] = $entry;
    }

    return [
        'funnel_id' => (int) $funnel->id,
        'contacts'  => $out,
        'total'     => (int) (clone $query)->count(),
        'per_page'  => $per_page,
        'page'      => nibwp_fcrm_page($in),
    ];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_fcrm_contact_emails(array $in): array|WP_Error
{
    $contact = nibwp_fcrm_find_contact($in);

    if ($contact instanceof WP_Error) {
        return $contact;
    }

    $model = nibwp_fcrm_model('CampaignEmail');

    if ($model === null) {
        return nibwp_fcrm_err('no_model', __('FluentCRM email history is unavailable on this version.', 'nibwp'));
    }

    $per_page = nibwp_fcrm_rows($in);
    $offset = (nibwp_fcrm_page($in) - 1) * $per_page;

    $query = $model::query()->where('subscriber_id', (int) $contact->id);
    $status = trim((string) ($in['status'] ?? ''));

    if ($status !== '') {
        $query->where('status', $status);
    }

    $rows = (clone $query)->orderBy('id', 'DESC')->limit($per_page)->offset($offset)->get();

    // Sequences live in the FluentCRM Pro add-on, not the free plugin
    // (Subscriber::sequences() resolves \FluentCampaign\App\Models\Sequence,
    // Subscriber.php:412). Saying so beats returning an empty array that reads
    // like "this contact is in none".
    $sequences = null;

    if (class_exists('FluentCampaign\\App\\Models\\SequenceTracker') && method_exists($contact, 'sequence_trackers')) {
        $sequences = [];

        try {
            foreach (nibwp_fcrm_rowset($contact->sequence_trackers) as $row) {
                $sequences[] = nibwp_fcrm_pick($row, ['id', 'campaign_id', 'subscriber_id', 'status', 'last_sequence_id', 'created_at', 'updated_at']);
            }
        } catch (\Throwable $error) {
            $sequences = [];
        }
    }

    return [
        'contact_id' => (int) $contact->id,
        'emails'     => nibwp_fcrm_map($rows, 'nibwp_fcrm_email'),
        'total'      => (int) (clone $query)->count(),
        'per_page'   => $per_page,
        'page'       => nibwp_fcrm_page($in),
        'sequences'  => $sequences,
        'sequences_note' => $sequences === null
            ? __('Email sequences need the FluentCRM Pro add-on, which is not active here.', 'nibwp')
            : null,
    ];
}

/**
 * The four things an owner would actually act on, plus what is configured.
 *
 * Deliberately opinionated about thresholds. "Here are your bounce rates" is a
 * report; "these four campaigns are at a rate that gets a sending domain
 * throttled" is something to do. The numbers below are the conventional industry
 * alarm points, and they are stated in the response so nobody has to guess what
 * the tool considers bad.
 */
function nibwp_fcrm_audit(array $in): array|WP_Error
{
    $days = max(1, min(365, (int) ($in['days'] ?? 30)));
    $rows = nibwp_fcrm_rows($in, 20);
    $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

    $findings = [];

    // 1. Contacts that never confirmed. Pending means FluentCRM sent a double
    // opt-in and never got a click; after a month they are not going to, and
    // they are counted in every list total the owner reads.
    $subscriber = nibwp_fcrm_model('Subscriber');

    if ($subscriber !== null) {
        try {
            $query = $subscriber::query()->where('status', 'pending')->where('created_at', '<', $cutoff);

            $findings['unconfirmed_contacts'] = [
                'older_than_days' => $days,
                'count'           => (int) (clone $query)->count(),
                'sample'          => nibwp_fcrm_map(
                    (clone $query)->orderBy('created_at', 'ASC')->limit($rows)->get(),
                    'nibwp_fcrm_contact'
                ),
                'why'             => __('These were sent a confirmation email and never clicked it. They cannot be mailed, and they inflate every list count.', 'nibwp'),
            ];
        } catch (\Throwable $error) {
            $findings['unconfirmed_contacts'] = null;
        }
    }

    // 2. Tags nothing uses. Each one is a filter in every segment builder on the
    // site, and an empty one is how a campaign gets sent to nobody.
    $findings['unused_tags'] = nibwp_fcrm_empty_terms('Tag', $rows);

    // 3. Lists with nobody on them, for the same reason.
    $findings['empty_lists'] = nibwp_fcrm_empty_terms('Lists', $rows);

    // 4. Campaigns whose deliverability is a problem. Bounces at 2% and above
    // get a sending domain throttled by the large mailbox providers;
    // unsubscribes at 0.5% and above mean the list did not expect the mail.
    $findings['risky_campaigns'] = nibwp_fcrm_risky_campaigns();

    // 5. What is configured — names and booleans only.
    $findings['configuration'] = nibwp_fcrm_configuration();

    return [
        'window_days' => $days,
        'thresholds'  => [
            'bounce_rate_percent'      => 2.0,
            'unsubscribe_rate_percent' => 0.5,
            'pending_older_than_days'  => $days,
        ],
        'findings' => $findings,
        'note'     => __('Nothing here changed anything. Deleting a contact destroys their consent record, so prefer change_contact_status to unsubscribed for anybody who should stop receiving mail.', 'nibwp'),
    ];
}

/** Tags or lists with no contacts attached, bounded. */
function nibwp_fcrm_empty_terms(string $model_name, int $rows): ?array
{
    $model = nibwp_fcrm_model($model_name);

    if ($model === null) {
        return null;
    }

    try {
        // Bounded before the counting, not after: totalCount() is a query per
        // row, and an unbounded pass over 2000 tags is the slowest thing in the
        // file. Alphabetical so the same page comes back twice.
        $candidates = $model::query()->orderBy('title', 'ASC')->limit(NIBWP_FCRM_MAX_ROWS)->get();
    } catch (\Throwable $error) {
        return null;
    }

    $empty = [];
    $examined = 0;

    foreach (nibwp_fcrm_rowset($candidates) as $row) {
        $examined++;

        try {
            $count = method_exists($row, 'totalCount') ? (int) $row->totalCount() : 0;
        } catch (\Throwable $error) {
            continue;
        }

        if ($count === 0) {
            $empty[] = nibwp_fcrm_term($row);
        }

        if (count($empty) >= $rows) {
            break;
        }
    }

    return [
        'count'    => count($empty),
        'examined' => $examined,
        'items'    => $empty,
        'why'      => __('Nothing is attached to these. They show up in every segment picker on the site, and picking one sends to nobody.', 'nibwp'),
    ];
}

/** Campaigns whose bounce or unsubscribe rate is high enough to matter. */
function nibwp_fcrm_risky_campaigns(): ?array
{
    $model = nibwp_fcrm_model('Campaign');

    if ($model === null) {
        return null;
    }

    try {
        $campaigns = $model::query()->where('status', 'sent')->orderBy('id', 'DESC')->limit(NIBWP_FCRM_AUDIT_CAMPAIGNS)->get();
    } catch (\Throwable $error) {
        return null;
    }

    $risky = [];
    $examined = 0;

    foreach (nibwp_fcrm_rowset($campaigns) as $campaign) {
        $examined++;
        $stats = nibwp_fcrm_campaign_stats($campaign);

        if ($stats['sent'] < 50) {
            // A 1-in-20 bounce on a 20-recipient send is one bad address, not a
            // deliverability problem, and reporting it as one wastes the owner's
            // afternoon.
            continue;
        }

        $reasons = [];

        if (($stats['bounce_rate'] ?? 0) >= 2.0) {
            $reasons[] = 'bounce_rate';
        }

        if (($stats['unsubscribe_rate'] ?? 0) >= 0.5) {
            $reasons[] = 'unsubscribe_rate';
        }

        if ($reasons === []) {
            continue;
        }

        $risky[] = $stats + ['reasons' => $reasons];
    }

    return [
        'count'    => count($risky),
        'examined' => $examined,
        'items'    => $risky,
        'why'      => __('A bounce rate at or above 2 percent, or an unsubscribe rate at or above 0.5 percent, is where mailbox providers start throttling a sending domain. Clean the addresses behind these before the next send.', 'nibwp'),
    ];
}

/**
 * What FluentCRM is configured with — never what it is configured with.
 *
 * The three settings whose values appear here are a sender name, a sender
 * address and a throttle. Everything a sending service, an SMTP connection or an
 * incoming webhook is addressed by is named and marked set or not, and its value
 * does not leave this function.
 */
function nibwp_fcrm_configuration(): array
{
    $settings = [];

    try {
        $helper = 'FluentCrm\\App\\Services\\Helper';
        $email = class_exists($helper) && method_exists($helper, 'getGlobalEmailSettings')
            ? call_user_func([$helper, 'getGlobalEmailSettings'])
            : [];
        $settings = is_array($email) ? $email : [];
    } catch (\Throwable $error) {
        $settings = [];
    }

    $safe = [];

    foreach (NIBWP_FCRM_SAFE_SETTINGS as $key) {
        $value = $settings[$key] ?? null;
        $safe[$key] = is_scalar($value) ? $value : null;
    }

    $compliance = [];

    try {
        $helper = 'FluentCrm\\App\\Services\\Helper';

        if (class_exists($helper) && method_exists($helper, 'getComplianceSettings')) {
            $raw = call_user_func([$helper, 'getComplianceSettings']);
            $raw = is_array($raw) ? $raw : [];

            foreach (NIBWP_FCRM_COMPLIANCE_FLAGS as $flag) {
                if (array_key_exists($flag, $raw) && is_scalar($raw[$flag])) {
                    $compliance[$flag] = $raw[$flag];
                }
            }
        }
    } catch (\Throwable $error) {
        $compliance = [];
    }

    $withheld = [];

    foreach (NIBWP_FCRM_WITHHELD_SETTINGS as $key => $label) {
        $withheld[] = [
            'name'           => $key,
            'what'           => $label,
            'is_set'         => nibwp_fcrm_credential_is_set($key, $settings),
            'value_returned' => false,
        ];
    }

    return [
        'fluentcrm_version' => defined('FLUENTCRM_PLUGIN_VERSION') ? FLUENTCRM_PLUGIN_VERSION : null,
        'pro_active'        => class_exists('FluentCampaign\\App\\Application'),
        'settings'          => $safe,
        'compliance'        => $compliance,
        'withheld'          => $withheld,
        'why'               => __('Anything that authenticates — a sending-service key, an SMTP password, a webhook secret, a contact token — is named and never returned. Whoever holds one can send mail as this site or rewrite somebody\'s consent.', 'nibwp'),
    ];
}

/**
 * Is a credential present? Yes or no, computed without the value going anywhere.
 *
 * The webhook answer counts rows rather than reading them, because the key IS the
 * row's value and a count cannot accidentally be the thing it counted.
 */
function nibwp_fcrm_credential_is_set(string $key, array $email_settings): ?bool
{
    if ($key === 'webhook_keys') {
        $webhook = nibwp_fcrm_model('Webhook');

        if ($webhook === null) {
            return null;
        }

        try {
            return $webhook::query()->count() > 0;
        } catch (\Throwable $error) {
            return null;
        }
    }

    if ($key === 'contact_secure_hashes') {
        // Always true on any site that has ever sent an email: FluentCRM mints one
        // per contact on first send. Reported as a reminder that they exist and
        // are withheld, not as a finding.
        return true;
    }

    $value = $email_settings[$key] ?? null;

    if ($value === null) {
        return false;
    }

    if (is_array($value)) {
        return $value !== [];
    }

    return trim((string) $value) !== '';
}
