<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: Events Manager.
 *
 * Distinct from the `events` hub, which is The Events Calendar. Both are events
 * plugins; neither knows about the other, and a site can run both. The action
 * vocabulary here is deliberately the same shape as that hub — list_events,
 * get_event, create_event, update_event, set_event_status, list_event_categories,
 * list_event_tags, set_event_terms, delete_event_permanently, audit_calendar — so
 * an agent that learned one finds the other. Where the nouns genuinely differ the
 * plugin's own noun wins: this plugin has LOCATIONS where the other has venues,
 * and it has BOOKINGS and TICKETS, which the other has no equivalent of at all.
 *
 * Verified against Events Manager 7.4.5 (events-manager.php:32).
 *
 * WHY EVERY WRITE GOES THROUGH THE PLUGIN'S OWN CLASSES
 * This plugin keeps its own tables beside the post types, and an event lives in
 * BOTH at once:
 *
 *   - wp_em_events holds the row; wp_posts holds the post; the link is
 *     em_events.post_id -> wp_posts.ID, mirrored back as the `_event_id` post
 *     meta (classes/em-event.php:1495).
 *   - The authoritative LOCAL start/end are event_start_date + event_start_time
 *     (and the _end_ pair) read in the event's own event_timezone. The
 *     authoritative UTC mirror is event_start / event_end, which EM derives from
 *     the local pair in EM_Event::to_array() (classes/em-event.php:3910-3911) and
 *     which every listing query orders and filters on. event_all_day is the
 *     all-day flag, and EM pins the times to 00:00:00/23:59:59 when it is set
 *     (em-install.php:2297).
 *
 * Write one store without the other and you get an event that appears in the
 * admin list and never on the calendar, or a row whose post was trashed under it.
 * EM_Event::save() writes the post and the row in one pass, including the UTC
 * mirror (classes/em-event.php:1346-1455 and :1467-1600); EM_Event::set_status()
 * moves wp_posts.post_status and em_events.event_status together when told to
 * (classes/em-event.php:1900-1950). So consistency stays the plugin's problem,
 * which is where it belongs — we never INSERT or UPDATE those tables ourselves.
 *
 * Raw SQL appears here for exactly two reasons and nowhere else: the audits, which
 * must look at the two stores SEPARATELY to find where they disagree, and bounded
 * counts that would otherwise hydrate thousands of objects to call count() on them.
 * Every value in those goes through $wpdb->prepare(), and every table name comes
 * from the plugin's own EM_*_TABLE constants via nibwp_em_tables() — never from
 * anything a caller sent, because this plugin builds its table names from a prefix
 * and an identifier cannot be prepared.
 *
 * PERSONAL DATA — the reason the booking actions are gated the way they are.
 * A booking is a real person: wp_em_bookings.person_id points at a user, and
 * booking_meta carries the registration they typed, including user_email and
 * dbem_phone (classes/em-booking.php:1091 and :1122). None of that leaves here.
 * Bookings come back as ids, counts, spaces, money and status, plus has_email /
 * has_phone booleans — the shape of the record without the person in it. Reading
 * them takes the capability the plugin gates its own Bookings screen on,
 * manage_bookings (admin/em-admin.php:43), with manage_others_bookings for
 * anything that reaches past the caller's own events (admin/em-bookings.php:115).
 * Not edit_posts, which half the site has.
 *
 * SECRETS: booking_meta also carries whatever a payment gateway left behind, and
 * the plugin's options hold an SMTP password, a Maps browser key and a licence key
 * (dbem_smtp_password, dbem_google_maps_browser_key, dbem_pro_api_key). Settings
 * leave here through an ALLOW-LIST of names whose value may travel; everything
 * else is a name and whether it is set. An allow-list because dbem_* is one flat
 * key space that grows every release, so a denylist would leak the next key added.
 * A value that is not a scalar is DROPPED rather than cast — "Array" is a lie and
 * a __toString() is a leak.
 *
 * WHAT IS NOT HERE, and will not be:
 *   - refunding a paid booking. Money leaving an account is not an agent's call.
 *   - deleting a booking, or a person's booking history. It is the record of what
 *     someone paid for and attended, and the plugin's own screens can do it with
 *     the consequences on screen.
 *   - changing a booking's spaces, price or taxes after the fact.
 * A test parses the enum and fails if an action whose name reads like any of those
 * ever appears, so a later edit has to argue with it.
 */

/** How many rows any listing will return, whatever was asked for. */
const NIBWP_EM_MAX_ROWS = 200;

/** The default a listing uses when the caller names no limit. */
const NIBWP_EM_DEFAULT_ROWS = 50;

/* The plugin's own capability names, from em-install.php:2157-2175. */
const NIBWP_EM_CAP_EVENTS = 'edit_events';
const NIBWP_EM_CAP_EVENTS_OTHERS = 'edit_others_events';
const NIBWP_EM_CAP_EVENTS_DELETE = 'delete_events';
const NIBWP_EM_CAP_EVENTS_DELETE_OTHERS = 'delete_others_events';
const NIBWP_EM_CAP_PUBLISH = 'publish_events';
const NIBWP_EM_CAP_LOCATIONS = 'edit_locations';
const NIBWP_EM_CAP_LOCATIONS_OTHERS = 'edit_others_locations';
const NIBWP_EM_CAP_TERMS = 'edit_event_categories';
const NIBWP_EM_CAP_BOOKINGS = 'manage_bookings';
const NIBWP_EM_CAP_BOOKINGS_OTHERS = 'manage_others_bookings';
const NIBWP_EM_CAP_SETTINGS = 'manage_options';

/**
 * Is Events Manager actually loaded, with the parts we use?
 *
 * EM_VERSION is defined by the main plugin file before anything else
 * (events-manager.php:32) and EM_Event is the class every write goes through, so
 * between them they separate "not installed" from "installed and half-loaded".
 */
function nibwp_em_guard(): ?WP_Error
{
    if (!defined('EM_VERSION') || !class_exists('EM_Event')) {
        return new WP_Error(
            'nibwp_em_missing',
            __('Events Manager is not active on this site.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/** A refusal about the request itself. */
function nibwp_em_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error('nibwp_em_' . $code, $message, ['status' => $status]);
}

/** A refusal about authority rather than about the request. */
function nibwp_em_forbidden(string $what, string $cap): WP_Error
{
    return new WP_Error(
        'nibwp_em_forbidden',
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
 * The single-capability door, for listings and anything with no one object to ask
 * about.
 *
 * Asked BEFORE any input is validated. A refusal that first says "there is no
 * event 41" tells a caller with no authority which ids exist, and a refusal that
 * says "start_date is malformed" tells it which values are accepted. Both are
 * answers to a question the caller was not allowed to ask.
 */
function nibwp_em_require(string $what, string $cap): ?WP_Error
{
    return current_user_can($cap) ? null : nibwp_em_forbidden($what, $cap);
}

/**
 * May this account manage this one object, given who owns it?
 *
 * EM's own split: the owner capability covers your own records, the admin
 * capability covers everybody's (EM_Object::can_manage(),
 * classes/em-object.php:1371-1405). We ask WordPress directly with EM's names
 * rather than calling can_manage(), because can_manage() also pushes a
 * translated error onto the object from $em_capabilities_array, which is only
 * populated on admin screens — outside wp-admin it returns the right answer with
 * an empty reason, and a refusal with no reason is not one we can hand back.
 */
function nibwp_em_require_owned(string $what, int $owner_id, string $own_cap, string $others_cap): ?WP_Error
{
    if (current_user_can($others_cap)) {
        return null;
    }

    $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;

    if ($owner_id > 0 && $user_id > 0 && $owner_id === $user_id && current_user_can($own_cap)) {
        return null;
    }

    return nibwp_em_forbidden($what, $others_cap);
}

/**
 * The booking gate.
 *
 * $owner_id is the id of the account that owns the EVENT the booking belongs to,
 * because that is what the plugin scopes bookings by: manage_bookings lets you
 * see bookings on your own events, manage_others_bookings reaches past them
 * (admin/em-bookings.php:115). An unscoped listing or count spans other people's
 * events by definition, so it is passed 0 and takes manage_others_bookings.
 */
function nibwp_em_require_bookings(string $what, int $owner_id): ?WP_Error
{
    return nibwp_em_require_owned($what, $owner_id, NIBWP_EM_CAP_BOOKINGS, NIBWP_EM_CAP_BOOKINGS_OTHERS);
}

/**
 * The plugin's table names, or a refusal.
 *
 * Read from the plugin's own constants, which it builds once from $wpdb->prefix
 * (or base_prefix in global-multisite mode) at events-manager.php:243-253. This
 * is the ONLY source of a table name in this file. A table name cannot be passed
 * through $wpdb->prepare() — an identifier is not a value — so the rule is that
 * it never comes from anywhere a caller can reach.
 *
 * @return array<string, string>|WP_Error
 */
function nibwp_em_tables(): array|WP_Error
{
    $map = [
        'events' => 'EM_EVENTS_TABLE',
        'locations' => 'EM_LOCATIONS_TABLE',
        'bookings' => 'EM_BOOKINGS_TABLE',
        'tickets' => 'EM_TICKETS_TABLE',
        'tickets_bookings' => 'EM_TICKETS_BOOKINGS_TABLE',
        'recurrences' => 'EM_EVENT_RECURRENCES_TABLE',
    ];

    $tables = [];

    foreach ($map as $key => $constant) {
        if (!defined($constant)) {
            return nibwp_em_err(
                'tables_missing',
                sprintf(
                    /* translators: %s: PHP constant name */
                    __('Events Manager has not defined %s yet, so its tables cannot be read.', 'nibwp'),
                    $constant
                ),
                503
            );
        }

        $name = (string) constant($constant);

        // Belt and braces. The constant comes from the plugin, but it lands in a
        // query as an identifier, and an identifier is the one thing prepare()
        // cannot defend. Anything that is not a plain table name stops here.
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            return nibwp_em_err(
                'tables_invalid',
                sprintf(
                    /* translators: %s: PHP constant name */
                    __('The table name in %s is not a plain identifier, so it will not be used in a query.', 'nibwp'),
                    $constant
                ),
                500
            );
        }

        $tables[$key] = $name;
    }

    return $tables;
}

/** Clamp a caller's count to something this site can afford to build. */
function nibwp_em_clamp(mixed $raw, int $default, int $max): int
{
    if (!is_numeric($raw)) {
        return $default;
    }

    $value = (int) $raw;

    if ($value < 1) {
        return $default;
    }

    return $value > $max ? $max : $value;
}

/** Where a listing starts. Never negative, never unbounded. */
function nibwp_em_offset(mixed $raw): int
{
    if (!is_numeric($raw)) {
        return 0;
    }

    $value = (int) $raw;

    return $value > 0 ? $value : 0;
}

/** The event post type, read off the plugin rather than hardcoded. */
function nibwp_em_event_post_types(): array
{
    // 'event-recurring' is the post type the plugin gives a recurring MASTER
    // (classes/em-event.php:1376), and it is still an event. A recurrence of one
    // has no post at all, which is why the row is what we resolve against.
    $types = ['event-recurring'];

    if (defined('EM_POST_TYPE_EVENT')) {
        $types[] = (string) EM_POST_TYPE_EVENT;
    }

    return $types;
}

/** The location post type, read off the plugin. */
function nibwp_em_location_post_type(): string
{
    return defined('EM_POST_TYPE_LOCATION') ? (string) EM_POST_TYPE_LOCATION : '';
}

/** The category taxonomy, read off the plugin (classes/em-taxonomies.php:10). */
function nibwp_em_category_taxonomy(): string
{
    return defined('EM_TAXONOMY_CATEGORY') ? (string) EM_TAXONOMY_CATEGORY : '';
}

/** The tag taxonomy, read off the plugin (classes/em-taxonomies.php:11). */
function nibwp_em_tag_taxonomy(): string
{
    return defined('EM_TAXONOMY_TAG') ? (string) EM_TAXONOMY_TAG : '';
}

/** EM stores an event's status as a small int; these are the names for it. */
function nibwp_em_event_status_label(mixed $raw): string
{
    if ($raw === null || $raw === '') {
        return 'draft';
    }

    $code = (int) $raw;

    if ($code === 1) {
        return 'publish';
    }

    if ($code === 0) {
        return 'pending';
    }

    return $code === -1 ? 'trash' : 'unknown';
}

/**
 * Turn a status name from a caller into the int EM stores, or a refusal.
 *
 * @return array{code: int|null}|WP_Error
 */
function nibwp_em_event_status_code(mixed $raw): array|WP_Error
{
    $name = is_scalar($raw) ? (string) $raw : '';

    switch ($name) {
        case 'publish':
            return ['code' => 1];
        case 'pending':
            return ['code' => 0];
        case 'trash':
            return ['code' => -1];
        case 'draft':
            return ['code' => null];
    }

    return nibwp_em_err(
        'bad_status',
        __('status must be one of publish, pending, draft, trash.', 'nibwp')
    );
}

/** The booking statuses, from EM_Booking::$status_array (classes/em-booking.php:202-212). */
function nibwp_em_booking_status_label(mixed $raw): string
{
    $labels = [
        0 => 'pending',
        1 => 'approved',
        2 => 'rejected',
        3 => 'cancelled',
        4 => 'awaiting_online_payment',
        5 => 'awaiting_payment',
        6 => 'waitlist',
        7 => 'waitlist_approved',
        8 => 'waitlist_expired',
    ];

    return $labels[(int) $raw] ?? 'unknown';
}

/**
 * A scalar, or nothing.
 *
 * Casting is how a secret escapes: (string) on an array is "Array" with a notice,
 * and on an object it runs __toString() and prints whatever that returns. A value
 * that is not already a scalar is dropped and the key never appears.
 */
function nibwp_em_scalar(mixed $value): mixed
{
    if (is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
        return $value;
    }

    return null;
}

/**
 * A YYYY-MM-DD date from a caller, or nothing.
 *
 * Checked rather than trusted even though EM re-checks it with its own regex
 * before building a scope condition (classes/em-object.php:211-222): the value
 * also reaches prepared statements in the audits, and a date that is merely
 * wrong produces a listing nobody can explain.
 */
function nibwp_em_date(mixed $raw): string
{
    $text = is_scalar($raw) ? trim((string) $raw) : '';

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
        return '';
    }

    $parts = explode('-', $text);

    return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]) ? $text : '';
}

/** A HH:MM or HH:MM:SS time from a caller, normalised, or nothing. */
function nibwp_em_time(mixed $raw): string
{
    $text = is_scalar($raw) ? trim((string) $raw) : '';

    if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $text, $found)) {
        return '';
    }

    $hour = (int) $found[1];
    $minute = (int) $found[2];
    $second = isset($found[3]) ? (int) $found[3] : 0;

    if ($hour > 23 || $minute > 59 || $second > 59) {
        return '';
    }

    return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
}

/** A timezone name the server actually knows, or nothing. */
function nibwp_em_timezone(mixed $raw): string
{
    $text = is_scalar($raw) ? trim((string) $raw) : '';

    if ($text === '') {
        return '';
    }

    try {
        new DateTimeZone($text);
    } catch (\Throwable $error) {
        return '';
    }

    return $text;
}

/** Truthiness from a caller, where "0" and "false" mean false. */
function nibwp_em_truthy(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return (int) $value !== 0;
    }

    if (is_string($value)) {
        return !in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off'], true);
    }

    return false;
}

/**
 * Read one EM row with the plugin's own table name and a prepared value.
 *
 * @return array<string, mixed>|null
 */
function nibwp_em_row(string $sql, array $args): ?array
{
    global $wpdb;

    if (!isset($wpdb) || !is_object($wpdb)) {
        return null;
    }

    try {
        $row = $wpdb->get_row($wpdb->prepare($sql, $args), ARRAY_A);
    } catch (\Throwable $error) {
        return null;
    }

    return is_array($row) ? $row : null;
}

/**
 * Turn a caller's event id into a real event row, and do NOTHING else.
 *
 * No capability is consulted here, deliberately. An id is a claim about an
 * object; until we know the object is an event, asking whether anyone may edit
 * it is asking the wrong question — and a delete that passes a capability check
 * on a page id is how an agent destroys the About page believing it cancelled an
 * event. The caller checks authority after this returns.
 *
 * This plugin makes the check unusually worthwhile. An event is a ROW plus a
 * POST, so "is this really an event" has two halves: a row must exist, and if the
 * row claims a post then that post must exist and be an event post type. A row
 * pointing at a page is exactly the disagreement this plugin's design permits,
 * and it is refused here rather than acted on.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_em_resolve_event(mixed $raw): array|WP_Error
{
    $id = is_numeric($raw) ? (int) $raw : 0;

    if ($id <= 0) {
        return nibwp_em_err('bad_request', __('A positive event_id is required.', 'nibwp'));
    }

    $tables = nibwp_em_tables();

    if ($tables instanceof WP_Error) {
        return $tables;
    }

    $row = nibwp_em_row(
        'SELECT event_id, post_id, event_owner, event_name, event_status, event_type, location_id,'
        . ' event_start_date, event_start_time, event_end_date, event_end_time, event_all_day, event_timezone,'
        . ' event_start, event_end, event_rsvp, event_spaces, recurrence_id, recurrence_set_id'
        . ' FROM ' . $tables['events'] . ' WHERE event_id = %d LIMIT %d',
        [$id, 1]
    );

    if ($row === null) {
        return nibwp_em_err(
            'not_an_event',
            sprintf(
                /* translators: %d: event ID */
                __('No Events Manager event row has the id %d.', 'nibwp'),
                $id
            ),
            404
        );
    }

    $post_id = isset($row['post_id']) ? (int) $row['post_id'] : 0;
    $post = null;

    if ($post_id > 0) {
        $post = get_post($post_id);
        $types = nibwp_em_event_post_types();

        if (!$post instanceof WP_Post || !in_array((string) $post->post_type, $types, true)) {
            // The code names the TYPE so a caller that handed over the id of a
            // page, or of an event whose post was replaced under it, learns the
            // problem is the object and not its own permissions.
            return nibwp_em_err(
                'not_an_event',
                sprintf(
                    /* translators: 1: event ID, 2: post ID, 3: the post type found, or "nothing" */
                    __('Event row %1$d points at post %2$d, which is a "%3$s" and not an event. Refusing on the type before considering permissions.', 'nibwp'),
                    $id,
                    $post_id,
                    $post instanceof WP_Post ? (string) $post->post_type : __('nothing', 'nibwp')
                ),
                409
            );
        }
    }

    return ['row' => $row, 'post' => $post, 'event_id' => $id, 'owner_id' => (int) ($row['event_owner'] ?? 0)];
}

/**
 * The same discipline for a location: a row, and a post of the right type if the
 * row claims one.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_em_resolve_location(mixed $raw): array|WP_Error
{
    $id = is_numeric($raw) ? (int) $raw : 0;

    if ($id <= 0) {
        return nibwp_em_err('bad_request', __('A positive location_id is required.', 'nibwp'));
    }

    $tables = nibwp_em_tables();

    if ($tables instanceof WP_Error) {
        return $tables;
    }

    $row = nibwp_em_row(
        'SELECT location_id, post_id, location_owner, location_name, location_status, location_slug,'
        . ' location_address, location_town, location_state, location_postcode, location_region,'
        . ' location_country, location_latitude, location_longitude'
        . ' FROM ' . $tables['locations'] . ' WHERE location_id = %d LIMIT %d',
        [$id, 1]
    );

    if ($row === null) {
        return nibwp_em_err(
            'not_a_location',
            sprintf(
                /* translators: %d: location ID */
                __('No Events Manager location row has the id %d.', 'nibwp'),
                $id
            ),
            404
        );
    }

    $post_id = isset($row['post_id']) ? (int) $row['post_id'] : 0;
    $post = null;
    $expected = nibwp_em_location_post_type();

    if ($post_id > 0) {
        $post = get_post($post_id);

        if ($expected === '' || !$post instanceof WP_Post || (string) $post->post_type !== $expected) {
            return nibwp_em_err(
                'not_a_location',
                sprintf(
                    /* translators: 1: location ID, 2: post ID, 3: the post type found, or "nothing" */
                    __('Location row %1$d points at post %2$d, which is a "%3$s" and not a location. Refusing on the type before considering permissions.', 'nibwp'),
                    $id,
                    $post_id,
                    $post instanceof WP_Post ? (string) $post->post_type : __('nothing', 'nibwp')
                ),
                409
            );
        }
    }

    return ['row' => $row, 'post' => $post, 'location_id' => $id, 'owner_id' => (int) ($row['location_owner'] ?? 0)];
}

/**
 * A booking id, resolved to its row and the owner of the event it belongs to.
 *
 * The owner matters because that is what decides which booking capability
 * applies, so it is fetched here rather than left to each caller to remember.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_em_resolve_booking(mixed $raw): array|WP_Error
{
    $id = is_numeric($raw) ? (int) $raw : 0;

    if ($id <= 0) {
        return nibwp_em_err('bad_request', __('A positive booking_id is required.', 'nibwp'));
    }

    $tables = nibwp_em_tables();

    if ($tables instanceof WP_Error) {
        return $tables;
    }

    $row = nibwp_em_row(
        'SELECT booking_id, event_id, person_id, booking_spaces, booking_status, booking_price,'
        . ' booking_taxes, booking_tax_rate, booking_date, booking_comment'
        . ' FROM ' . $tables['bookings'] . ' WHERE booking_id = %d LIMIT %d',
        [$id, 1]
    );

    if ($row === null) {
        return nibwp_em_err(
            'not_a_booking',
            sprintf(
                /* translators: %d: booking ID */
                __('No Events Manager booking row has the id %d.', 'nibwp'),
                $id
            ),
            404
        );
    }

    $event_id = isset($row['event_id']) ? (int) $row['event_id'] : 0;
    $owner_id = 0;

    if ($event_id > 0) {
        $event_row = nibwp_em_row(
            'SELECT event_owner, event_name, event_start_date, event_end_date, event_spaces'
            . ' FROM ' . $tables['events'] . ' WHERE event_id = %d LIMIT %d',
            [$event_id, 1]
        );

        if ($event_row !== null) {
            $owner_id = (int) ($event_row['event_owner'] ?? 0);
        }
    }

    return ['row' => $row, 'booking_id' => $id, 'event_id' => $event_id, 'owner_id' => $owner_id];
}

/**
 * The fields of a booking that may leave this site, and what each one is.
 *
 * An allow-list, not a filter over the row: booking_meta is a serialised blob
 * holding the registration form (user_email, dbem_phone — classes/em-booking.php:1091,
 * :1122) and whatever a gateway wrote next to it, and no amount of key-name
 * guessing makes that safe to forward. person_id is here because it identifies
 * the account without describing the person; name, email and phone are not here
 * at all, at any capability.
 *
 * @return array<string, string>
 */
function nibwp_em_booking_public_fields(): array
{
    return [
        'booking_id' => 'int',
        'event_id' => 'int',
        'person_id' => 'int',
        'booking_spaces' => 'int',
        'booking_status' => 'int',
        'booking_price' => 'float',
        'booking_taxes' => 'float',
        'booking_tax_rate' => 'float',
        'booking_date' => 'string',
    ];
}

/**
 * One booking, as much of it as may travel.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function nibwp_em_booking_row(array $row): array
{
    $out = [];

    foreach (nibwp_em_booking_public_fields() as $key => $as) {
        if (!array_key_exists($key, $row)) {
            continue;
        }

        $value = nibwp_em_scalar($row[$key]);

        if ($value === null) {
            continue;
        }

        if ($as === 'int') {
            $out[$key] = (int) $value;
        } elseif ($as === 'float') {
            $out[$key] = (float) $value;
        } else {
            $out[$key] = (string) $value;
        }
    }

    $out['status'] = nibwp_em_booking_status_label($row['booking_status'] ?? 0);

    // Whether there is a person to contact, which is the only part of the contact
    // details an agent needs in order to tell someone they exist.
    $out['has_comment'] = isset($row['booking_comment']) && is_string($row['booking_comment']) && trim($row['booking_comment']) !== '';
    $out['contact_withheld'] = true;

    return $out;
}

/**
 * An event row as it leaves here.
 *
 * Both date pairs are reported: the LOCAL wall clock with its timezone, which is
 * what a person means by "when", and the UTC mirror, which is what every listing
 * query in the plugin actually filters on. Reporting one and not the other is how
 * a timezone bug hides.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function nibwp_em_event_row(array $row, ?WP_Post $post = null): array
{
    $event_id = (int) ($row['event_id'] ?? 0);
    $post_id = (int) ($row['post_id'] ?? 0);

    $out = [
        'event_id' => $event_id,
        'post_id' => $post_id,
        'name' => (string) ($row['event_name'] ?? ''),
        'status' => nibwp_em_event_status_label($row['event_status'] ?? null),
        'status_code' => isset($row['event_status']) && $row['event_status'] !== null ? (int) $row['event_status'] : null,
        // From the post when we have one; otherwise from whatever the hydrated
        // object carried, which for a recurrence is all there is.
        'post_status' => $post instanceof WP_Post ? (string) $post->post_status : (string) ($row['post_status'] ?? ''),
        'event_type' => (string) ($row['event_type'] ?? ''),
        'start_local' => trim((string) ($row['event_start_date'] ?? '') . ' ' . (string) ($row['event_start_time'] ?? '')),
        'end_local' => trim((string) ($row['event_end_date'] ?? '') . ' ' . (string) ($row['event_end_time'] ?? '')),
        'start_utc' => (string) ($row['event_start'] ?? ''),
        'end_utc' => (string) ($row['event_end'] ?? ''),
        'timezone' => (string) ($row['event_timezone'] ?? ''),
        'all_day' => !empty($row['event_all_day']),
        'location_id' => (int) ($row['location_id'] ?? 0),
        'bookings_enabled' => !empty($row['event_rsvp']),
        'spaces' => (int) ($row['event_spaces'] ?? 0),
        'owner_id' => (int) ($row['event_owner'] ?? 0),
        'recurrence_id' => (int) ($row['recurrence_id'] ?? 0),
        'recurrence_set_id' => (int) ($row['recurrence_set_id'] ?? 0),
    ];

    if ($post_id > 0 && function_exists('get_permalink')) {
        $out['url'] = (string) get_permalink($post_id);
    }

    return $out;
}

/**
 * A location row as it leaves here.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function nibwp_em_location_row(array $row, ?WP_Post $post = null): array
{
    return [
        'location_id' => (int) ($row['location_id'] ?? 0),
        'post_id' => (int) ($row['post_id'] ?? 0),
        'name' => (string) ($row['location_name'] ?? ''),
        'slug' => (string) ($row['location_slug'] ?? ''),
        'status' => nibwp_em_event_status_label($row['location_status'] ?? null),
        'post_status' => $post instanceof WP_Post ? (string) $post->post_status : '',
        'address' => (string) ($row['location_address'] ?? ''),
        'town' => (string) ($row['location_town'] ?? ''),
        'state' => (string) ($row['location_state'] ?? ''),
        'postcode' => (string) ($row['location_postcode'] ?? ''),
        'region' => (string) ($row['location_region'] ?? ''),
        'country' => (string) ($row['location_country'] ?? ''),
        'latitude' => isset($row['location_latitude']) ? (float) $row['location_latitude'] : 0.0,
        'longitude' => isset($row['location_longitude']) ? (float) $row['location_longitude'] : 0.0,
        'owner_id' => (int) ($row['location_owner'] ?? 0),
    ];
}

/**
 * The actions this hub offers.
 *
 * @return array<int, string>
 */
function nibwp_em_actions(): array
{
    return [
        // Events.
        'list_events',
        'get_event',
        'create_event',
        'update_event',
        'set_event_status',
        'delete_event_permanently',
        'list_recurring_events',
        // Locations — what a calendar plugin would call venues.
        'list_locations',
        'get_location',
        'create_location',
        'update_location',
        // Taxonomies.
        'list_event_categories',
        'list_event_tags',
        'set_event_terms',
        // Bookings and tickets.
        'list_bookings',
        'get_booking',
        'count_bookings',
        'approve_booking',
        'reject_booking',
        'list_tickets',
        'ticket_availability',
        // Posture.
        'get_settings',
        // Audits — the failures this plugin's two-store design makes possible.
        'audit_calendar',
        'audit_post_table_mismatch',
        'audit_events_without_location',
        'audit_event_dates',
        'audit_bookings',
    ];
}

wp_register_ability('nibwp/events-manager-manage', [
    'label' => __('Events Manager – Events, Locations and Bookings', 'nibwp'),
    'description' => 'Read and manage events, locations, categories, tags, bookings and tickets in Events Manager. '
        . 'This plugin keeps its own database tables beside the WordPress posts, and an event only works when both agree — '
        . 'every write here goes through the plugin\'s own EM_Event and EM_Location classes so it stays that way, and five audits report where it already does not. '
        . 'Bookings are real people: contact details and payment data never leave, and reading bookings takes the manage_bookings capability the plugin gates its own Bookings screen on. '
        . 'Nothing here can refund a booking, delete a booking, or change what someone was charged.',
    'category' => 'content',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => nibwp_em_actions(),
                'description' => 'Which operation to run. Start with list_events or audit_calendar.',
            ],
            'event_id' => ['type' => 'integer', 'description' => 'The Events Manager event id (NOT the post id).'],
            'location_id' => ['type' => 'integer', 'description' => 'The Events Manager location id.'],
            'booking_id' => ['type' => 'integer', 'description' => 'The Events Manager booking id.'],
            'ticket_id' => ['type' => 'integer', 'description' => 'For ticket_availability: one ticket id.'],
            'event_data' => ['type' => 'object', 'description' => 'For create_event / update_event. Keys: name, content, start_date, start_time, end_date, end_time, timezone, all_day, location_id, bookings_enabled, spaces. Publishing is set_event_status, not a key here.'],
            'location_data' => ['type' => 'object', 'description' => 'For create_location / update_location. Keys: name, content, address, town, state, postcode, region, country, latitude, longitude.'],
            'status' => ['type' => 'string', 'description' => 'For list_events / set_event_status: publish, pending, draft, trash, or any.'],
            'booking_status' => ['type' => 'string', 'description' => 'For list_bookings / count_bookings: pending, approved, rejected, cancelled, or any.'],
            'start_date' => ['type' => 'string', 'description' => 'Range start, YYYY-MM-DD, in the site\'s local time.'],
            'end_date' => ['type' => 'string', 'description' => 'Range end, YYYY-MM-DD, in the site\'s local time.'],
            'categories' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'For set_event_terms: category names or ids.'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'For set_event_terms: tag names or ids.'],
            'reason' => ['type' => 'string', 'description' => 'For approve_booking / reject_booking: a note recorded against the booking.'],
            'send_email' => ['type' => 'boolean', 'description' => 'For approve_booking / reject_booking: whether the plugin emails the person. Default true, which is what the plugin does on its own screens.'],
            'confirm' => ['type' => 'string', 'description' => 'For delete_event_permanently: must be the exact event name.'],
            'limit' => ['type' => 'integer', 'description' => 'Rows to return. Default 50, max 200.'],
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
    'execute_callback' => 'nibwp_em_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Events Manager. This is NOT the same plugin as the "events" hub, which is a',
                'different calendar. If a site has both, pick the one that owns the event.',
                '',
                'THE ONE THING TO UNDERSTAND: this plugin stores every event TWICE — a row in',
                'its own wp_em_events table and a WordPress post — and an event only behaves',
                'when both agree. Writing one without the other produces an event that shows',
                'in the admin list and never on the calendar, or the reverse. Every write here',
                'goes through the plugin\'s own classes so both move together. Do not look for',
                'an action that writes a date, a status or a location directly; there is not',
                'one, and that is the point.',
                '',
                'START WITH audit_calendar on a site you did not set up. It reports the five',
                'failures this design permits: a row whose post is missing or is not an event,',
                'an event with no location, an event whose end is before its start, bookings',
                'still pending after the event has happened, and an event at capacity that is',
                'still taking bookings. Each has its own action if you want only one.',
                '',
                'DATES: give start_date and start_time in the event\'s own local time and name',
                'its timezone. The plugin derives the UTC mirror itself. Responses carry both',
                'the local pair and the UTC pair, so if they disagree you can see it.',
                '',
                'LOCATIONS are what another calendar plugin calls venues. Same four actions:',
                'list_locations, get_location, create_location, update_location.',
                '',
                'BOOKINGS ARE PEOPLE. A booking record holds somebody\'s name, email address',
                'and often a phone number. None of it comes back here, at any capability, and',
                'asking differently will not change that — you get ids, spaces, money and',
                'status, plus whether a comment was left. Reading bookings needs',
                'manage_bookings, and anything spanning events you do not own needs',
                'manage_others_bookings. Prefer count_bookings over list_bookings when a',
                'number answers the question.',
                '',
                'APPROVING OR REJECTING A BOOKING SENDS AN EMAIL TO A REAL PERSON by default,',
                'using the plugin\'s own templates. Say so before you do it, say which booking,',
                'and say afterwards that the mail went. Pass send_email false only if the',
                'person asked you to change the status quietly.',
                '',
                'WHAT THIS CANNOT DO, by design: refund a booking, delete a booking or anyone\'s',
                'booking history, or change what someone was charged. Those belong to a person',
                'in wp-admin with the money in front of them, and saying so is the correct',
                'answer when asked.',
                '',
                'delete_event_permanently DESTROYS THE EVENT\'S BOOKINGS along with the event —',
                'the plugin cascades. It requires the event\'s exact name in `confirm`. Offer',
                'set_event_status with trash first; it is reversible and keeps the bookings.',
                '',
                'SECRETS: get_settings reports values only for a short list of booking and',
                'display switches. The SMTP password, the Maps browser key and the licence key',
                'come back as a name and whether they are set, never as a value.',
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
function nibwp_em_manage(array $input): array|WP_Error
{
    $guard = nibwp_em_guard();

    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $action = isset($input['action']) ? (string) $input['action'] : '';

    if (!in_array($action, nibwp_em_actions(), true)) {
        return nibwp_em_err(
            'unknown_action',
            sprintf(
                /* translators: 1: action name, 2: list of valid actions */
                __('Unknown Events Manager action "%1$s". Valid actions: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_em_actions())
            )
        );
    }

    // The plugin's classes reach the database, the options table and its own
    // date machinery, and on a site mid-upgrade any of that raises. One catch
    // here turns it into a named refusal rather than a fatal that takes the whole
    // REST request down with it.
    try {
        return nibwp_em_dispatch($action, $input);
    } catch (\Throwable $error) {
        return nibwp_em_err(
            'plugin_error',
            sprintf(
                /* translators: 1: action name, 2: error message from Events Manager */
                __('Events Manager could not complete %1$s: %2$s', 'nibwp'),
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
function nibwp_em_dispatch(string $action, array $input): array|WP_Error
{
    $result = match ($action) {
        'list_events' => nibwp_em_list_events($input),
        'get_event' => nibwp_em_get_event($input),
        'create_event' => nibwp_em_create_event($input),
        'update_event' => nibwp_em_update_event($input),
        'set_event_status' => nibwp_em_set_event_status($input),
        'delete_event_permanently' => nibwp_em_delete_event_permanently($input),
        'list_recurring_events' => nibwp_em_list_recurring_events($input),
        'list_locations' => nibwp_em_list_locations($input),
        'get_location' => nibwp_em_get_location($input),
        'create_location' => nibwp_em_create_location($input),
        'update_location' => nibwp_em_update_location($input),
        'list_event_categories' => nibwp_em_list_terms('category', $input),
        'list_event_tags' => nibwp_em_list_terms('tag', $input),
        'set_event_terms' => nibwp_em_set_event_terms($input),
        'list_bookings' => nibwp_em_list_bookings($input),
        'get_booking' => nibwp_em_get_booking($input),
        'count_bookings' => nibwp_em_count_bookings($input),
        'approve_booking' => nibwp_em_decide_booking('approve', $input),
        'reject_booking' => nibwp_em_decide_booking('reject', $input),
        'list_tickets' => nibwp_em_list_tickets($input),
        'ticket_availability' => nibwp_em_ticket_availability($input),
        'get_settings' => nibwp_em_get_settings(),
        'audit_calendar' => nibwp_em_audit_calendar($input),
        'audit_post_table_mismatch' => nibwp_em_audit_post_table_mismatch($input),
        'audit_events_without_location' => nibwp_em_audit_events_without_location($input),
        'audit_event_dates' => nibwp_em_audit_event_dates($input),
        'audit_bookings' => nibwp_em_audit_bookings($input),
        default => nibwp_em_err('unhandled_action', __('Unhandled action.', 'nibwp')),
    };

    return $result instanceof WP_Error ? $result : ['action' => $action] + $result;
}

/* ------------------------------------------------------------------ events */

/**
 * Read the fields we report off a hydrated EM_Event.
 *
 * Property reads on EM_Event run through its __get(), which for some keys reaches
 * the database and for others builds an EM_DateTime (classes/em-event.php:624-664).
 * Any of that can raise on a half-migrated site, and losing a whole listing to one
 * bad row is worse than losing the row, so each read stands alone.
 *
 * @return array<string, mixed>
 */
function nibwp_em_event_fields(object $event): array
{
    $keys = [
        'post_id', 'event_owner', 'event_name', 'event_status', 'event_type', 'location_id',
        'event_start_date', 'event_start_time', 'event_end_date', 'event_end_time',
        'event_all_day', 'event_timezone', 'event_start', 'event_end',
        'event_rsvp', 'event_spaces', 'recurrence_id', 'recurrence_set_id', 'post_status',
    ];

    $out = [];

    foreach ($keys as $key) {
        try {
            $out[$key] = nibwp_em_scalar($event->$key ?? null);
        } catch (\Throwable $error) {
            $out[$key] = null;
        }
    }

    // event_id comes back as a composite "event:timeslot" string for a timeslot
    // event (classes/em-event.php:626), so the numeric id is taken from the base
    // accessor and the composite reported beside it rather than silently truncated.
    try {
        $out['event_id'] = method_exists($event, 'get_event_id') ? (int) $event->get_event_id() : (int) ($event->event_id ?? 0);
        $uid = nibwp_em_scalar($event->event_id ?? null);

        if (is_string($uid) && $uid !== (string) $out['event_id']) {
            $out['event_uid'] = $uid;
        }
    } catch (\Throwable $error) {
        $out['event_id'] = 0;
    }

    return $out;
}

/**
 * The search arguments for a bounded event listing, or a refusal.
 *
 * Built for EM_Events::get(), which is the plugin's own query builder: it knows
 * which of the two date pairs this site searches on, it applies the private and
 * archetype rules, and it validates a date range itself before using it
 * (classes/em-object.php:210-222). A hand-rolled query would have to know all of
 * that and would drift from it at the next release.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_em_event_search(array $input, bool $recurring): array|WP_Error
{
    $limit = nibwp_em_clamp($input['limit'] ?? null, NIBWP_EM_DEFAULT_ROWS, NIBWP_EM_MAX_ROWS);
    $offset = nibwp_em_offset($input['offset'] ?? null);

    $status = is_scalar($input['status'] ?? null) ? (string) $input['status'] : '';
    $status_arg = false;

    if ($status !== '' && $status !== 'any') {
        $decoded = nibwp_em_event_status_code($status);

        if ($decoded instanceof WP_Error) {
            return $decoded;
        }

        $status_arg = $decoded['code'];
    }

    $args = [
        'limit' => $limit,
        'offset' => $offset,
        'status' => $status_arg,
        'orderby' => 'event_start_date,event_start_time',
        'order' => 'ASC',
        'recurring' => $recurring,
    ];

    $start = nibwp_em_date($input['start_date'] ?? null);
    $end = nibwp_em_date($input['end_date'] ?? null);

    if (($input['start_date'] ?? null) !== null && $start === '') {
        return nibwp_em_err('bad_date', __('start_date must be a real date in YYYY-MM-DD form.', 'nibwp'));
    }

    if (($input['end_date'] ?? null) !== null && $end === '') {
        return nibwp_em_err('bad_date', __('end_date must be a real date in YYYY-MM-DD form.', 'nibwp'));
    }

    if ($start !== '' && $end !== '' && $end < $start) {
        return nibwp_em_err('bad_date', __('end_date is before start_date.', 'nibwp'));
    }

    if ($start !== '' || $end !== '') {
        // EM reads a "from,to" scope and treats either half as open
        // (classes/em-object.php:211). Both halves are validated above, so what
        // reaches the plugin is two real dates or an empty side and nothing else.
        $args['scope'] = $start . ',' . $end;
    } elseif (!$recurring) {
        // Without a range the plugin's own default scope is "future", which
        // quietly hides everything that already happened. A listing that claims
        // to be the calendar should be the calendar.
        $args['scope'] = 'all';
    }

    return ['args' => $args, 'limit' => $limit, 'offset' => $offset];
}

/**
 * Run an EM_Events::get() and turn the result into rows.
 *
 * @param array<string, mixed> $args
 * @return array<int, array<string, mixed>>|WP_Error
 */
function nibwp_em_events_query(array $args): array|WP_Error
{
    if (!class_exists('EM_Events') || !method_exists('EM_Events', 'get')) {
        return nibwp_em_err(
            'plugin_api_missing',
            __('Events Manager did not load its EM_Events class, so events cannot be listed.', 'nibwp'),
            503
        );
    }

    $found = EM_Events::get($args);
    $rows = [];

    foreach ((array) $found as $event) {
        if (!is_object($event)) {
            continue;
        }

        $fields = nibwp_em_event_fields($event);
        $row = nibwp_em_event_row($fields);

        if (isset($fields['event_uid'])) {
            $row['event_uid'] = (string) $fields['event_uid'];
        }

        $rows[] = $row;
    }

    return $rows;
}

/** list_events — a bounded window on the calendar, over the range and status asked for. */
function nibwp_em_list_events(array $input): array|WP_Error
{
    $denied = nibwp_em_require(__('Listing events', 'nibwp'), NIBWP_EM_CAP_EVENTS);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $search = nibwp_em_event_search($input, false);

    if ($search instanceof WP_Error) {
        return $search;
    }

    $rows = nibwp_em_events_query($search['args']);

    if ($rows instanceof WP_Error) {
        return $rows;
    }

    return [
        'events' => $rows,
        'count' => count($rows),
        'limit' => $search['limit'],
        'offset' => $search['offset'],
        'scope' => (string) ($search['args']['scope'] ?? ''),
    ];
}

/**
 * list_recurring_events — the masters, with the pattern each one repeats on.
 *
 * This build does expose recurrences readably, so they are reported rather than
 * refused: the masters are the events EM_Events::get() returns for the `recurring`
 * argument (classes/em-events.php:841-844), and the pattern lives in its own table
 * read through EM_Event::get_recurrence_sets() (classes/em-event.php:2345,
 * classes/recurrences/recurrence-set.php:54-85).
 */
function nibwp_em_list_recurring_events(array $input): array|WP_Error
{
    $denied = nibwp_em_require(__('Listing recurring events', 'nibwp'), NIBWP_EM_CAP_EVENTS);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $search = nibwp_em_event_search($input, true);

    if ($search instanceof WP_Error) {
        return $search;
    }

    if (!class_exists('EM_Events') || !method_exists('EM_Events', 'get')) {
        return nibwp_em_err(
            'plugin_api_missing',
            __('Events Manager did not load its EM_Events class, so recurring events cannot be listed.', 'nibwp'),
            503
        );
    }

    $found = EM_Events::get($search['args']);
    $rows = [];

    foreach ((array) $found as $event) {
        if (!is_object($event)) {
            continue;
        }

        $row = nibwp_em_event_row(nibwp_em_event_fields($event));
        $row['recurrence_patterns'] = nibwp_em_recurrence_patterns($event);
        $rows[] = $row;
    }

    return [
        'recurring_events' => $rows,
        'count' => count($rows),
        'limit' => $search['limit'],
        'offset' => $search['offset'],
    ];
}

/**
 * The repeat rules of one recurring master, read through the plugin.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_em_recurrence_patterns(object $event): array
{
    if (!method_exists($event, 'get_recurrence_sets')) {
        return [];
    }

    try {
        $sets = $event->get_recurrence_sets();
    } catch (\Throwable $error) {
        return [];
    }

    $keys = [
        'recurrence_set_id', 'recurrence_order', 'recurrence_type', 'recurrence_freq',
        'recurrence_interval', 'recurrence_byday', 'recurrence_byweekno', 'recurrence_duration',
        'recurrence_start_date', 'recurrence_end_date', 'recurrence_start_time',
        'recurrence_end_time', 'recurrence_all_day', 'recurrence_timezone', 'recurrence_status',
    ];

    $out = [];

    foreach ((array) $sets as $set) {
        if (!is_object($set)) {
            continue;
        }

        $pattern = [];

        foreach ($keys as $key) {
            try {
                $value = nibwp_em_scalar($set->$key ?? null);
            } catch (\Throwable $error) {
                $value = null;
            }

            if ($value !== null && $value !== '') {
                $pattern[$key] = $value;
            }
        }

        if ($pattern !== []) {
            $out[] = $pattern;
        }

        if (count($out) >= NIBWP_EM_MAX_ROWS) {
            break;
        }
    }

    return $out;
}

/** get_event — the row and the post, and whether they agree. */
function nibwp_em_get_event(array $input): array|WP_Error
{
    // Type first, alone: an id is a claim, and asking who may edit object 12
    // before knowing what object 12 is answers the wrong question.
    $resolved = nibwp_em_resolve_event($input['event_id'] ?? null);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $denied = nibwp_em_require_owned(
        __('Reading this event', 'nibwp'),
        (int) $resolved['owner_id'],
        NIBWP_EM_CAP_EVENTS,
        NIBWP_EM_CAP_EVENTS_OTHERS
    );

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $row = $resolved['row'];
    $post = $resolved['post'] instanceof WP_Post ? $resolved['post'] : null;
    $event = nibwp_em_event_row($row, $post);

    // The two stores side by side, because this is the plugin where they can
    // drift and the drift is invisible on either screen on its own.
    $event['consistency'] = [
        'has_post' => $post instanceof WP_Post,
        'row_status' => nibwp_em_event_status_label($row['event_status'] ?? null),
        'post_status' => $post instanceof WP_Post ? (string) $post->post_status : '',
        'name_matches' => $post instanceof WP_Post
            ? (string) $post->post_title === (string) ($row['event_name'] ?? '')
            : null,
        'status_agrees' => $post instanceof WP_Post
            ? nibwp_em_statuses_agree($row['event_status'] ?? null, (string) $post->post_status)
            : null,
    ];

    return ['event' => $event];
}

/**
 * Do the row's status and the post's status say the same thing?
 *
 * The mapping is the plugin's own, from EM_Event::set_status()
 * (classes/em-event.php:1900-1950): 1 is a published post, 0 is pending, -1 is
 * trash, NULL is a draft.
 */
function nibwp_em_statuses_agree(mixed $row_status, string $post_status): bool
{
    if ($row_status === null || $row_status === '') {
        return $post_status === 'draft' || $post_status === 'auto-draft';
    }

    $code = (int) $row_status;

    if ($code === 1) {
        return $post_status === 'publish' || $post_status === 'private';
    }

    if ($code === 0) {
        return $post_status === 'pending';
    }

    return $code === -1 ? $post_status === 'trash' : false;
}

/**
 * Turn a caller's event payload into properties to set on an EM_Event.
 *
 * Only keys the plugin's own fields map names (classes/em-event.php:231-266), so
 * what we set is what it saves. The UTC mirror is deliberately NOT among them:
 * EM derives event_start and event_end from the local pair itself when it writes
 * the row (classes/em-event.php:3910-3911), and a value computed here would be a
 * second opinion that wins on half the queries.
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>|WP_Error
 */
function nibwp_em_event_input(array $data, bool $is_create): array|WP_Error
{
    $fields = [];

    if (array_key_exists('name', $data)) {
        $name = sanitize_text_field(is_scalar($data['name']) ? (string) $data['name'] : '');

        if ($name === '') {
            return nibwp_em_err('bad_request', __('name cannot be empty.', 'nibwp'));
        }

        $fields['event_name'] = $name;
    } elseif ($is_create) {
        return nibwp_em_err('bad_request', __('event_data.name is required to create an event.', 'nibwp'));
    }

    if (array_key_exists('content', $data)) {
        $plain = is_scalar($data['content']) ? (string) $data['content'] : '';
        // Slashed, because EM hands this straight to wp_insert_post(), which
        // unslashes (classes/em-event.php:1399) — so an unslashed value arrives at
        // the post with its backslashes and quotes eaten. No kses of our own:
        // whoever may write a post decides its markup, and a second filter here
        // would swallow somebody's shortcode without telling them.
        //
        // EM also copies this same string into em_events.post_content through
        // $wpdb, which does NOT unslash, so that column ends up holding the
        // slashed form. That is harmless and deliberately left alone: post_content
        // is in EM_Event::$post_fields (classes/em-event.php:267), the list of
        // fields EM stopped reading from its own table, and load_postdata() takes
        // it from the post instead (classes/em-event.php:806). The one path that
        // does read the column is a POST-LESS event — a recurrence hydrated
        // straight from the row (classes/em-event.php:494) — and this hub never
        // creates one of those. Writing the column ourselves to "fix" it would
        // mean a second write to a store the plugin owns, which is the thing this
        // whole integration is built to avoid.
        $fields['post_content'] = function_exists('nibwp_wp_prepare_post_content')
            ? (string) nibwp_wp_prepare_post_content($plain)
            : wp_slash($plain);
    }

    $start_date = null;

    if (array_key_exists('start_date', $data)) {
        $start_date = nibwp_em_date($data['start_date']);

        if ($start_date === '') {
            return nibwp_em_err('bad_date', __('start_date must be a real date in YYYY-MM-DD form.', 'nibwp'));
        }

        $fields['event_start_date'] = $start_date;
    } elseif ($is_create) {
        return nibwp_em_err('bad_request', __('event_data.start_date is required to create an event.', 'nibwp'));
    }

    $end_date = null;

    if (array_key_exists('end_date', $data)) {
        $end_date = nibwp_em_date($data['end_date']);

        if ($end_date === '') {
            return nibwp_em_err('bad_date', __('end_date must be a real date in YYYY-MM-DD form.', 'nibwp'));
        }

        $fields['event_end_date'] = $end_date;
    } elseif ($is_create && $start_date !== null) {
        // A one-day event is the common case, and an event with no end date is
        // one the plugin's own listings cannot place.
        $fields['event_end_date'] = $start_date;
        $end_date = $start_date;
    }

    $all_day = array_key_exists('all_day', $data) ? nibwp_em_truthy($data['all_day']) : null;

    if ($all_day !== null) {
        $fields['event_all_day'] = $all_day ? 1 : 0;
    }

    foreach (['start_time' => 'event_start_time', 'end_time' => 'event_end_time'] as $key => $field) {
        if (!array_key_exists($key, $data)) {
            continue;
        }

        $time = nibwp_em_time($data[$key]);

        if ($time === '') {
            return nibwp_em_err(
                'bad_time',
                sprintf(
                    /* translators: %s: the field name, e.g. start_time */
                    __('%s must be a time in HH:MM or HH:MM:SS form.', 'nibwp'),
                    $key
                )
            );
        }

        $fields[$field] = $time;
    }

    if ($all_day === true) {
        // The plugin pins an all-day event to the whole day (em-install.php:2297),
        // and a stray 14:00 on an all-day event is what makes it sort wrongly.
        $fields['event_start_time'] = '00:00:00';
        $fields['event_end_time'] = '23:59:59';
    } elseif ($is_create) {
        $fields['event_start_time'] = $fields['event_start_time'] ?? '00:00:00';
        $fields['event_end_time'] = $fields['event_end_time'] ?? '23:59:59';
    }

    if ($start_date !== null && $end_date !== null && $end_date < $start_date) {
        return nibwp_em_err('bad_dates', __('The event would end before it starts.', 'nibwp'));
    }

    if ($start_date !== null && $end_date !== null && $start_date === $end_date) {
        $from = (string) ($fields['event_start_time'] ?? '');
        $to = (string) ($fields['event_end_time'] ?? '');

        if ($from !== '' && $to !== '' && $to < $from) {
            return nibwp_em_err('bad_dates', __('The event would end before it starts.', 'nibwp'));
        }
    }

    if (array_key_exists('timezone', $data)) {
        $zone = nibwp_em_timezone($data['timezone']);

        if ($zone === '') {
            return nibwp_em_err('bad_timezone', __('timezone must be a timezone this server knows, such as Europe/Rome.', 'nibwp'));
        }

        $fields['event_timezone'] = $zone;
    }

    if (array_key_exists('location_id', $data)) {
        $location_id = is_numeric($data['location_id']) ? (int) $data['location_id'] : -1;

        if ($location_id < 0) {
            return nibwp_em_err('bad_request', __('location_id must be a location id, or 0 for no location.', 'nibwp'));
        }

        if ($location_id > 0) {
            $location = nibwp_em_resolve_location($location_id);

            if ($location instanceof WP_Error) {
                return $location;
            }
        }

        $fields['location_id'] = $location_id;
    }

    if (array_key_exists('bookings_enabled', $data)) {
        $fields['event_rsvp'] = nibwp_em_truthy($data['bookings_enabled']) ? 1 : 0;
    }

    if (array_key_exists('spaces', $data)) {
        if (!is_numeric($data['spaces']) || (int) $data['spaces'] < 0) {
            return nibwp_em_err('bad_request', __('spaces must be zero or more.', 'nibwp'));
        }

        $fields['event_spaces'] = (int) $data['spaces'];
    }

    if ($fields === []) {
        return nibwp_em_err('bad_request', __('event_data holds nothing this action can change.', 'nibwp'));
    }

    return ['fields' => $fields];
}

/**
 * Whatever the plugin objected to, as text.
 *
 * EM collects validation problems on the object and returns false from save()
 * rather than raising (EM_Object::get_errors(), classes/em-object.php:1613), so
 * reading them back out is the only way to say why a save failed.
 */
function nibwp_em_plugin_errors(object $thing): string
{
    $errors = [];

    try {
        if (method_exists($thing, 'get_errors')) {
            $errors = (array) $thing->get_errors();
        } elseif (property_exists($thing, 'errors')) {
            $errors = (array) $thing->errors;
        }
    } catch (\Throwable $error) {
        $errors = [];
    }

    $clean = [];

    foreach ($errors as $message) {
        $scalar = nibwp_em_scalar($message);

        if ($scalar !== null && (string) $scalar !== '') {
            $clean[] = wp_strip_all_tags((string) $scalar);
        }
    }

    return $clean === [] ? __('the plugin gave no reason', 'nibwp') : implode(' ', $clean);
}

/** create_event — one EM_Event::save(), which writes the post and the row together. */
function nibwp_em_create_event(array $input): array|WP_Error
{
    // Capability before validation. A refusal that opens with "start_date is
    // required" has already told an unauthorised caller what this hub accepts.
    $denied = nibwp_em_require(__('Creating an event', 'nibwp'), NIBWP_EM_CAP_EVENTS);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $data = is_array($input['event_data'] ?? null) ? $input['event_data'] : [];
    $built = nibwp_em_event_input($data, true);

    if ($built instanceof WP_Error) {
        return $built;
    }

    if (!class_exists('EM_Event')) {
        return nibwp_em_err(
            'plugin_api_missing',
            __('Events Manager did not load its EM_Event class, so an event cannot be created.', 'nibwp'),
            503
        );
    }

    $event = new EM_Event();

    foreach ($built['fields'] as $key => $value) {
        $event->$key = $value;
    }

    if (!method_exists($event, 'save') || !$event->save()) {
        return nibwp_em_err(
            'save_failed',
            sprintf(
                /* translators: %s: the reason the plugin gave */
                __('Events Manager refused to save the event: %s', 'nibwp'),
                nibwp_em_plugin_errors($event)
            ),
            422
        );
    }

    $event_id = (int) (method_exists($event, 'get_event_id') ? $event->get_event_id() : ($event->event_id ?? 0));

    $saved = nibwp_em_resolve_event($event_id);

    return [
        'created' => true,
        'event' => $saved instanceof WP_Error
            ? nibwp_em_event_row(nibwp_em_event_fields($event))
            : nibwp_em_event_row($saved['row'], $saved['post'] instanceof WP_Post ? $saved['post'] : null),
        'fields_written' => array_values(array_keys($built['fields'])),
        'stores_written' => ['wp_posts', 'em_events', 'postmeta'],
    ];
}

/** update_event — the same save path, on an id that was type-checked first. */
function nibwp_em_update_event(array $input): array|WP_Error
{
    $resolved = nibwp_em_resolve_event($input['event_id'] ?? null);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $denied = nibwp_em_require_owned(
        __('Updating this event', 'nibwp'),
        (int) $resolved['owner_id'],
        NIBWP_EM_CAP_EVENTS,
        NIBWP_EM_CAP_EVENTS_OTHERS
    );

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $data = is_array($input['event_data'] ?? null) ? $input['event_data'] : [];

    if ($data === []) {
        return nibwp_em_err('bad_request', __('event_data is empty, so there is nothing to change.', 'nibwp'));
    }

    $built = nibwp_em_event_input($data, false);

    if ($built instanceof WP_Error) {
        return $built;
    }

    if (!class_exists('EM_Event')) {
        return nibwp_em_err(
            'plugin_api_missing',
            __('Events Manager did not load its EM_Event class, so the event cannot be updated.', 'nibwp'),
            503
        );
    }

    $event_id = (int) $resolved['event_id'];
    $event = new EM_Event($event_id);

    // A hydrate that came back empty would be saved as a NEW event, which is the
    // worst possible outcome of a bad id.
    $loaded = (int) (method_exists($event, 'get_event_id') ? $event->get_event_id() : ($event->event_id ?? 0));

    if ($loaded !== $event_id) {
        return nibwp_em_err(
            'load_failed',
            sprintf(
                /* translators: %d: event ID */
                __('Events Manager could not load event %d, so nothing was changed.', 'nibwp'),
                $event_id
            ),
            500
        );
    }

    foreach ($built['fields'] as $key => $value) {
        $event->$key = $value;
    }

    if (!method_exists($event, 'save') || !$event->save()) {
        return nibwp_em_err(
            'save_failed',
            sprintf(
                /* translators: %s: the reason the plugin gave */
                __('Events Manager refused to save the event: %s', 'nibwp'),
                nibwp_em_plugin_errors($event)
            ),
            422
        );
    }

    $saved = nibwp_em_resolve_event($event_id);

    return [
        'updated' => true,
        'event' => $saved instanceof WP_Error
            ? nibwp_em_event_row(nibwp_em_event_fields($event))
            : nibwp_em_event_row($saved['row'], $saved['post'] instanceof WP_Post ? $saved['post'] : null),
        'fields_changed' => array_values(array_keys($built['fields'])),
    ];
}

/**
 * set_event_status — publishing is its own decision, so it is its own action.
 *
 * Goes through EM_Event::set_status() with the post flag ON, which is what moves
 * wp_posts.post_status and em_events.event_status in one call
 * (classes/em-event.php:1900-1950). Setting only one of them is the commonest way
 * to end up with an event that lists in wp-admin and never on the calendar.
 */
function nibwp_em_set_event_status(array $input): array|WP_Error
{
    $resolved = nibwp_em_resolve_event($input['event_id'] ?? null);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $wanted = is_scalar($input['status'] ?? null) ? (string) $input['status'] : '';

    // Publishing is a different authority from editing, so which capability is
    // asked for depends on where the event is going — but it is still asked
    // before the status name is validated.
    $own_cap = $wanted === 'publish' ? NIBWP_EM_CAP_PUBLISH : NIBWP_EM_CAP_EVENTS;
    $others_cap = $wanted === 'publish' ? NIBWP_EM_CAP_PUBLISH : NIBWP_EM_CAP_EVENTS_OTHERS;

    $denied = nibwp_em_require_owned(
        __('Changing this event status', 'nibwp'),
        (int) $resolved['owner_id'],
        $own_cap,
        $others_cap
    );

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $decoded = nibwp_em_event_status_code($wanted);

    if ($decoded instanceof WP_Error) {
        return $decoded;
    }

    if (!class_exists('EM_Event')) {
        return nibwp_em_err(
            'plugin_api_missing',
            __('Events Manager did not load its EM_Event class, so the status cannot be changed.', 'nibwp'),
            503
        );
    }

    $event_id = (int) $resolved['event_id'];
    $event = new EM_Event($event_id);

    if (!method_exists($event, 'set_status')) {
        return nibwp_em_err(
            'plugin_api_missing',
            __('This build of Events Manager has no set_status() method, so the status cannot be changed safely.', 'nibwp'),
            503
        );
    }

    $before = nibwp_em_event_status_label($resolved['row']['event_status'] ?? null);
    $event->set_status($decoded['code'], true);

    $after = nibwp_em_resolve_event($event_id);

    return [
        'updated' => true,
        'event_id' => $event_id,
        'status_before' => $before,
        'status_after' => $after instanceof WP_Error
            ? $wanted
            : nibwp_em_event_status_label($after['row']['event_status'] ?? null),
        'post_status_after' => $after instanceof WP_Error || !$after['post'] instanceof WP_Post
            ? ''
            : (string) $after['post']->post_status,
        'both_stores_moved' => true,
    ];
}

/**
 * delete_event_permanently — and with it, every booking anyone ever made on it.
 *
 * EM cascades: deleting the event takes the em_events row, the tickets and the
 * bookings with it (EM_Event::delete() -> delete_meta() -> delete_bookings(),
 * classes/em-event.php:1791-1899). There is no version of this that keeps the
 * bookings, which is why the event's exact name has to be typed back and why the
 * instructions offer set_event_status with trash first.
 */
function nibwp_em_delete_event_permanently(array $input): array|WP_Error
{
    $resolved = nibwp_em_resolve_event($input['event_id'] ?? null);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $denied = nibwp_em_require_owned(
        __('Permanently deleting this event and its bookings', 'nibwp'),
        (int) $resolved['owner_id'],
        NIBWP_EM_CAP_EVENTS_DELETE,
        NIBWP_EM_CAP_EVENTS_DELETE_OTHERS
    );

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $name = (string) ($resolved['row']['event_name'] ?? '');
    $confirm = is_scalar($input['confirm'] ?? null) ? (string) $input['confirm'] : '';

    if ($confirm === '' || $confirm !== $name) {
        return nibwp_em_err(
            'confirm_required',
            sprintf(
                /* translators: %s: the event name */
                __('This destroys the event AND every booking made on it, and cannot be undone. Pass confirm with the exact event name ("%s"), or use set_event_status with trash instead, which is reversible and keeps the bookings.', 'nibwp'),
                $name
            ),
            409
        );
    }

    $event_id = (int) $resolved['event_id'];
    $bookings = nibwp_em_count_rows('bookings', 'event_id = %d', [$event_id]);

    if (!class_exists('EM_Event')) {
        return nibwp_em_err(
            'plugin_api_missing',
            __('Events Manager did not load its EM_Event class, so the event cannot be deleted.', 'nibwp'),
            503
        );
    }

    $event = new EM_Event($event_id);

    if (!method_exists($event, 'delete') || !$event->delete(true)) {
        return nibwp_em_err(
            'delete_failed',
            sprintf(
                /* translators: %s: the reason the plugin gave */
                __('Events Manager refused to delete the event: %s', 'nibwp'),
                nibwp_em_plugin_errors($event)
            ),
            422
        );
    }

    return [
        'deleted' => true,
        'event_id' => $event_id,
        'event_name' => $name,
        'bookings_destroyed' => $bookings,
        'warning' => __('The event, its tickets and every booking on it are gone. This cannot be undone.', 'nibwp'),
    ];
}

/* --------------------------------------------------------------- locations */

/**
 * list_locations — bounded, through the plugin's own query builder.
 *
 * Called locations here because that is the plugin's own noun. They are the same
 * thing another calendar plugin calls a venue, and the four actions are the same
 * four: list, get, create, update.
 */
function nibwp_em_list_locations(array $input): array|WP_Error
{
    $denied = nibwp_em_require(__('Listing locations', 'nibwp'), NIBWP_EM_CAP_LOCATIONS);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $limit = nibwp_em_clamp($input['limit'] ?? null, NIBWP_EM_DEFAULT_ROWS, NIBWP_EM_MAX_ROWS);
    $offset = nibwp_em_offset($input['offset'] ?? null);

    $status = is_scalar($input['status'] ?? null) ? (string) $input['status'] : '';
    $status_arg = false;

    if ($status !== '' && $status !== 'any') {
        $decoded = nibwp_em_event_status_code($status);

        if ($decoded instanceof WP_Error) {
            return $decoded;
        }

        $status_arg = $decoded['code'];
    }

    if (!class_exists('EM_Locations') || !method_exists('EM_Locations', 'get')) {
        return nibwp_em_err(
            'plugin_api_missing',
            __('Events Manager did not load its EM_Locations class, so locations cannot be listed.', 'nibwp'),
            503
        );
    }

    $found = EM_Locations::get([
        'limit' => $limit,
        'offset' => $offset,
        'status' => $status_arg,
        'orderby' => 'location_name',
        'order' => 'ASC',
    ]);

    $rows = [];

    foreach ((array) $found as $location) {
        if (!is_object($location)) {
            continue;
        }

        $fields = [];

        foreach (['location_id', 'post_id', 'location_owner', 'location_name', 'location_status', 'location_slug', 'location_address', 'location_town', 'location_state', 'location_postcode', 'location_region', 'location_country', 'location_latitude', 'location_longitude', 'post_status'] as $key) {
            try {
                $fields[$key] = nibwp_em_scalar($location->$key ?? null);
            } catch (\Throwable $error) {
                $fields[$key] = null;
            }
        }

        $rows[] = nibwp_em_location_row($fields);
    }

    return [
        'locations' => $rows,
        'count' => count($rows),
        'limit' => $limit,
        'offset' => $offset,
    ];
}

/** get_location — one location, with how many events point at it. */
function nibwp_em_get_location(array $input): array|WP_Error
{
    $resolved = nibwp_em_resolve_location($input['location_id'] ?? null);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $denied = nibwp_em_require_owned(
        __('Reading this location', 'nibwp'),
        (int) $resolved['owner_id'],
        NIBWP_EM_CAP_LOCATIONS,
        NIBWP_EM_CAP_LOCATIONS_OTHERS
    );

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $location = nibwp_em_location_row(
        $resolved['row'],
        $resolved['post'] instanceof WP_Post ? $resolved['post'] : null
    );
    $location['event_count'] = nibwp_em_count_rows('events', 'location_id = %d', [(int) $resolved['location_id']]);

    return ['location' => $location];
}

/**
 * A caller's location payload, as properties for EM_Location.
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>|WP_Error
 */
function nibwp_em_location_input(array $data, bool $is_create): array|WP_Error
{
    $fields = [];

    $text_map = [
        'name' => 'location_name',
        'address' => 'location_address',
        'town' => 'location_town',
        'state' => 'location_state',
        'postcode' => 'location_postcode',
        'region' => 'location_region',
        'country' => 'location_country',
    ];

    foreach ($text_map as $key => $field) {
        if (!array_key_exists($key, $data)) {
            continue;
        }

        $value = sanitize_text_field(is_scalar($data[$key]) ? (string) $data[$key] : '');

        if ($key === 'name' && $value === '') {
            return nibwp_em_err('bad_request', __('name cannot be empty.', 'nibwp'));
        }

        $fields[$field] = $value;
    }

    if ($is_create) {
        // The plugin's own validator wants a name, an address and a town
        // (EM_Location::validate()), and a location that fails validation saves
        // as a draft nobody will ever find.
        foreach (['name' => 'location_name', 'address' => 'location_address', 'town' => 'location_town'] as $key => $field) {
            if (!isset($fields[$field]) || $fields[$field] === '') {
                return nibwp_em_err(
                    'bad_request',
                    sprintf(
                        /* translators: %s: the field name, e.g. town */
                        __('location_data.%s is required to create a location.', 'nibwp'),
                        $key
                    )
                );
            }
        }
    }

    if (array_key_exists('content', $data)) {
        $plain = is_scalar($data['content']) ? (string) $data['content'] : '';
        // Slashed for the same reason as an event's, and the location row's own
        // copy is left to the plugin for the same reason too.
        $fields['post_content'] = function_exists('nibwp_wp_prepare_post_content')
            ? (string) nibwp_wp_prepare_post_content($plain)
            : wp_slash($plain);
    }

    foreach (['latitude' => 'location_latitude', 'longitude' => 'location_longitude'] as $key => $field) {
        if (!array_key_exists($key, $data)) {
            continue;
        }

        if (!is_numeric($data[$key])) {
            return nibwp_em_err(
                'bad_request',
                sprintf(
                    /* translators: %s: the field name, e.g. latitude */
                    __('%s must be a number.', 'nibwp'),
                    $key
                )
            );
        }

        $value = (float) $data[$key];
        $bound = $key === 'latitude' ? 90.0 : 180.0;

        if ($value < -$bound || $value > $bound) {
            return nibwp_em_err(
                'bad_request',
                sprintf(
                    /* translators: 1: the field name, 2: the limit */
                    __('%1$s must be between -%2$s and %2$s.', 'nibwp'),
                    $key,
                    (string) $bound
                )
            );
        }

        $fields[$field] = $value;
    }

    if ($fields === []) {
        return nibwp_em_err('bad_request', __('location_data holds nothing this action can change.', 'nibwp'));
    }

    return ['fields' => $fields];
}

/** create_location — EM_Location::save(), which writes the post and the row together. */
function nibwp_em_create_location(array $input): array|WP_Error
{
    $denied = nibwp_em_require(__('Creating a location', 'nibwp'), NIBWP_EM_CAP_LOCATIONS);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $data = is_array($input['location_data'] ?? null) ? $input['location_data'] : [];
    $built = nibwp_em_location_input($data, true);

    if ($built instanceof WP_Error) {
        return $built;
    }

    if (!class_exists('EM_Location')) {
        return nibwp_em_err(
            'plugin_api_missing',
            __('Events Manager did not load its EM_Location class, so a location cannot be created.', 'nibwp'),
            503
        );
    }

    $location = new EM_Location();

    foreach ($built['fields'] as $key => $value) {
        $location->$key = $value;
    }

    if (!method_exists($location, 'save') || !$location->save()) {
        return nibwp_em_err(
            'save_failed',
            sprintf(
                /* translators: %s: the reason the plugin gave */
                __('Events Manager refused to save the location: %s', 'nibwp'),
                nibwp_em_plugin_errors($location)
            ),
            422
        );
    }

    $location_id = (int) ($location->location_id ?? 0);
    $saved = nibwp_em_resolve_location($location_id);

    return [
        'created' => true,
        'location' => $saved instanceof WP_Error
            ? ['location_id' => $location_id]
            : nibwp_em_location_row($saved['row'], $saved['post'] instanceof WP_Post ? $saved['post'] : null),
        'fields_written' => array_values(array_keys($built['fields'])),
        'stores_written' => ['wp_posts', 'em_locations', 'postmeta'],
    ];
}

/** update_location — same path, on an id that was type-checked first. */
function nibwp_em_update_location(array $input): array|WP_Error
{
    $resolved = nibwp_em_resolve_location($input['location_id'] ?? null);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $denied = nibwp_em_require_owned(
        __('Updating this location', 'nibwp'),
        (int) $resolved['owner_id'],
        NIBWP_EM_CAP_LOCATIONS,
        NIBWP_EM_CAP_LOCATIONS_OTHERS
    );

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $data = is_array($input['location_data'] ?? null) ? $input['location_data'] : [];

    if ($data === []) {
        return nibwp_em_err('bad_request', __('location_data is empty, so there is nothing to change.', 'nibwp'));
    }

    $built = nibwp_em_location_input($data, false);

    if ($built instanceof WP_Error) {
        return $built;
    }

    if (!class_exists('EM_Location')) {
        return nibwp_em_err(
            'plugin_api_missing',
            __('Events Manager did not load its EM_Location class, so the location cannot be updated.', 'nibwp'),
            503
        );
    }

    $location_id = (int) $resolved['location_id'];
    $location = new EM_Location($location_id);

    if ((int) ($location->location_id ?? 0) !== $location_id) {
        return nibwp_em_err(
            'load_failed',
            sprintf(
                /* translators: %d: location ID */
                __('Events Manager could not load location %d, so nothing was changed.', 'nibwp'),
                $location_id
            ),
            500
        );
    }

    foreach ($built['fields'] as $key => $value) {
        $location->$key = $value;
    }

    if (!method_exists($location, 'save') || !$location->save()) {
        return nibwp_em_err(
            'save_failed',
            sprintf(
                /* translators: %s: the reason the plugin gave */
                __('Events Manager refused to save the location: %s', 'nibwp'),
                nibwp_em_plugin_errors($location)
            ),
            422
        );
    }

    $saved = nibwp_em_resolve_location($location_id);

    return [
        'updated' => true,
        'location' => $saved instanceof WP_Error
            ? ['location_id' => $location_id]
            : nibwp_em_location_row($saved['row'], $saved['post'] instanceof WP_Post ? $saved['post'] : null),
        'fields_changed' => array_values(array_keys($built['fields'])),
    ];
}

/* ------------------------------------------------------ categories and tags */

/** list_event_categories / list_event_tags — bounded term listings. */
function nibwp_em_list_terms(string $kind, array $input): array|WP_Error
{
    $denied = nibwp_em_require(
        $kind === 'tag' ? __('Listing event tags', 'nibwp') : __('Listing event categories', 'nibwp'),
        NIBWP_EM_CAP_EVENTS
    );

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $taxonomy = $kind === 'tag' ? nibwp_em_tag_taxonomy() : nibwp_em_category_taxonomy();

    if ($taxonomy === '') {
        return nibwp_em_err(
            'taxonomy_missing',
            __('Events Manager has not registered that taxonomy on this site.', 'nibwp'),
            503
        );
    }

    $limit = nibwp_em_clamp($input['limit'] ?? null, NIBWP_EM_DEFAULT_ROWS, NIBWP_EM_MAX_ROWS);
    $offset = nibwp_em_offset($input['offset'] ?? null);

    $terms = get_terms([
        'taxonomy' => $taxonomy,
        'hide_empty' => false,
        'number' => $limit,
        'offset' => $offset,
        'orderby' => 'name',
        'order' => 'ASC',
    ]);

    if ($terms instanceof WP_Error) {
        return nibwp_em_err('taxonomy_error', $terms->get_error_message(), 500);
    }

    $rows = [];

    foreach ((array) $terms as $term) {
        if (!is_object($term)) {
            continue;
        }

        $rows[] = [
            'term_id' => (int) ($term->term_id ?? 0),
            'name' => (string) ($term->name ?? ''),
            'slug' => (string) ($term->slug ?? ''),
            'parent' => (int) ($term->parent ?? 0),
            'count' => (int) ($term->count ?? 0),
        ];
    }

    return [
        'taxonomy' => $taxonomy,
        'count' => count($rows),
        'limit' => $limit,
        'offset' => $offset,
        // Named for what it holds, so a caller reading the response does not have
        // to know which action produced it.
        $kind === 'tag' ? 'tags' : 'categories' => $rows,
    ];
}

/**
 * set_event_terms — categories and tags on one event.
 *
 * wp_set_object_terms() on the event's POST, which is what the plugin's own API
 * layer does for exactly this (classes/api/em-api-service.php:1350-1353) and what
 * EM_Taxonomy_Terms::save() comes down to on a single site
 * (classes/em-taxonomy-terms.php:114-117). The extra em_meta index that save()
 * maintains exists only for global-multisite lookups, so an event with no post —
 * a recurrence — is refused here rather than half-tagged.
 */
function nibwp_em_set_event_terms(array $input): array|WP_Error
{
    $resolved = nibwp_em_resolve_event($input['event_id'] ?? null);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $denied = nibwp_em_require_owned(
        __('Changing the categories or tags on this event', 'nibwp'),
        (int) $resolved['owner_id'],
        NIBWP_EM_CAP_TERMS,
        NIBWP_EM_CAP_TERMS
    );

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $post_id = (int) ($resolved['row']['post_id'] ?? 0);

    if ($post_id <= 0) {
        return nibwp_em_err(
            'no_post',
            __('This event has no WordPress post — it is a recurrence — so terms belong on its recurring master instead.', 'nibwp'),
            409
        );
    }

    $wanted = [];

    foreach (['categories' => nibwp_em_category_taxonomy(), 'tags' => nibwp_em_tag_taxonomy()] as $key => $taxonomy) {
        if (!array_key_exists($key, $input)) {
            continue;
        }

        if (!is_array($input[$key])) {
            return nibwp_em_err(
                'bad_request',
                sprintf(
                    /* translators: %s: the field name, categories or tags */
                    __('%s must be an array of term names or ids.', 'nibwp'),
                    $key
                )
            );
        }

        if ($taxonomy === '') {
            return nibwp_em_err(
                'taxonomy_missing',
                __('Events Manager has not registered that taxonomy on this site.', 'nibwp'),
                503
            );
        }

        $terms = [];

        foreach ($input[$key] as $term) {
            $scalar = nibwp_em_scalar($term);

            if ($scalar === null) {
                continue;
            }

            if (is_int($scalar) || (is_string($scalar) && ctype_digit($scalar))) {
                $terms[] = (int) $scalar;
                continue;
            }

            $name = sanitize_text_field((string) $scalar);

            if ($name !== '') {
                $terms[] = $name;
            }
        }

        $wanted[$key] = ['taxonomy' => $taxonomy, 'terms' => $terms];
    }

    if ($wanted === []) {
        return nibwp_em_err('bad_request', __('Pass categories, tags, or both. An empty array clears that taxonomy.', 'nibwp'));
    }

    $applied = [];

    foreach ($wanted as $key => $set) {
        $result = wp_set_object_terms($post_id, $set['terms'], $set['taxonomy']);

        if ($result instanceof WP_Error) {
            return nibwp_em_err('taxonomy_error', $result->get_error_message(), 500);
        }

        $applied[$key] = array_values(array_map('intval', (array) $result));
    }

    return [
        'updated' => true,
        'event_id' => (int) $resolved['event_id'],
        'post_id' => $post_id,
        'applied' => $applied,
    ];
}

/* --------------------------------------------------- bookings and tickets */

/**
 * Count rows in one of the plugin's tables.
 *
 * $where is only ever a literal from this file; the values that fill its
 * placeholders are the only thing a caller reaches, and they go through
 * prepare(). The table name comes from nibwp_em_tables().
 *
 * @param array<int, mixed> $args
 */
function nibwp_em_count_rows(string $table_key, string $where, array $args): int
{
    global $wpdb;

    $tables = nibwp_em_tables();

    if ($tables instanceof WP_Error || !isset($tables[$table_key]) || !isset($wpdb) || !is_object($wpdb)) {
        return 0;
    }

    try {
        $count = $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM ' . $tables[$table_key] . ' WHERE ' . $where, $args)
        );
    } catch (\Throwable $error) {
        return 0;
    }

    return (int) $count;
}

/**
 * Read bounded rows out of one of the plugin's tables.
 *
 * @param array<int, mixed> $args
 * @return array<int, array<string, mixed>>
 */
function nibwp_em_results(string $sql, array $args): array
{
    global $wpdb;

    if (!isset($wpdb) || !is_object($wpdb)) {
        return [];
    }

    try {
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
    } catch (\Throwable $error) {
        return [];
    }

    $out = [];

    foreach ((array) $rows as $row) {
        if (is_array($row)) {
            $out[] = $row;
        }
    }

    return $out;
}

/** The booking status names a caller may filter on, mapped to what EM stores. */
function nibwp_em_booking_status_code(mixed $raw): ?int
{
    $map = [
        'pending' => 0,
        'approved' => 1,
        'rejected' => 2,
        'cancelled' => 3,
        'awaiting_online_payment' => 4,
        'awaiting_payment' => 5,
        'waitlist' => 6,
        'waitlist_approved' => 7,
        'waitlist_expired' => 8,
    ];

    $name = is_scalar($raw) ? (string) $raw : '';

    return $map[$name] ?? null;
}

/**
 * list_bookings — bounded, and with nobody's name in it.
 *
 * The one read in this file that deliberately does NOT go through the plugin's
 * classes. EM_Bookings::get() hydrates an EM_Booking per row, and an EM_Booking
 * will build an EM_Person holding a name, an email address and a phone number the
 * moment anything touches it (classes/em-booking.php:1070-1130). A SELECT of the
 * nine allow-listed columns cannot do that, so the contact record is never
 * assembled in the first place. Nothing is written here, so there is no
 * consistency to hand back to the plugin.
 */
function nibwp_em_list_bookings(array $input): array|WP_Error
{
    $owner_id = 0;
    $event_id = 0;
    $event_name = '';

    // An event id narrows the listing, and whose event it is decides which
    // booking capability applies — so it is resolved first, as a type check, with
    // no capability asked yet.
    if (($input['event_id'] ?? null) !== null) {
        $resolved = nibwp_em_resolve_event($input['event_id']);

        if ($resolved instanceof WP_Error) {
            return $resolved;
        }

        $owner_id = (int) $resolved['owner_id'];
        $event_id = (int) $resolved['event_id'];
        $event_name = (string) ($resolved['row']['event_name'] ?? '');
    }

    $denied = nibwp_em_require_bookings(__('Listing bookings', 'nibwp'), $owner_id);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $limit = nibwp_em_clamp($input['limit'] ?? null, NIBWP_EM_DEFAULT_ROWS, NIBWP_EM_MAX_ROWS);
    $offset = nibwp_em_offset($input['offset'] ?? null);

    $tables = nibwp_em_tables();

    if ($tables instanceof WP_Error) {
        return $tables;
    }

    $where = ['booking_id > %d'];
    $args = [0];

    if ($event_id > 0) {
        $where[] = 'event_id = %d';
        $args[] = $event_id;
    }

    $status = $input['booking_status'] ?? null;

    if ($status !== null && $status !== 'any') {
        $code = nibwp_em_booking_status_code($status);

        if ($code === null) {
            return nibwp_em_err(
                'bad_status',
                __('booking_status must be one of pending, approved, rejected, cancelled, awaiting_payment, awaiting_online_payment, waitlist, waitlist_approved, waitlist_expired, or any.', 'nibwp')
            );
        }

        $where[] = 'booking_status = %d';
        $args[] = $code;
    }

    $args[] = $limit;
    $args[] = $offset;

    $rows = nibwp_em_results(
        'SELECT booking_id, event_id, person_id, booking_spaces, booking_status, booking_price,'
        . ' booking_taxes, booking_tax_rate, booking_date, booking_comment'
        . ' FROM ' . $tables['bookings'] . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY booking_date DESC LIMIT %d OFFSET %d',
        $args
    );

    $bookings = [];

    foreach ($rows as $row) {
        $bookings[] = nibwp_em_booking_row($row);
    }

    return [
        'bookings' => $bookings,
        'count' => count($bookings),
        'limit' => $limit,
        'offset' => $offset,
        'event_id' => $event_id,
        'event_name' => $event_name,
        'contact_details_withheld' => __('Names, email addresses and phone numbers are never returned by this hub.', 'nibwp'),
    ];
}

/** get_booking — one booking, same allow-list, same silence about the person. */
function nibwp_em_get_booking(array $input): array|WP_Error
{
    $resolved = nibwp_em_resolve_booking($input['booking_id'] ?? null);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $denied = nibwp_em_require_bookings(__('Reading this booking', 'nibwp'), (int) $resolved['owner_id']);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $booking = nibwp_em_booking_row($resolved['row']);
    $booking['tickets'] = nibwp_em_booking_ticket_lines((int) $resolved['booking_id']);

    return [
        'booking' => $booking,
        'contact_details_withheld' => __('Names, email addresses and phone numbers are never returned by this hub.', 'nibwp'),
    ];
}

/**
 * Which tickets one booking is for, and how many of each.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_em_booking_ticket_lines(int $booking_id): array
{
    $tables = nibwp_em_tables();

    if ($tables instanceof WP_Error || $booking_id <= 0) {
        return [];
    }

    $rows = nibwp_em_results(
        'SELECT tb.ticket_id, tb.ticket_booking_spaces, tb.ticket_booking_price, t.ticket_name'
        . ' FROM ' . $tables['tickets_bookings'] . ' tb'
        . ' LEFT JOIN ' . $tables['tickets'] . ' t ON t.ticket_id = tb.ticket_id'
        . ' WHERE tb.booking_id = %d LIMIT %d',
        [$booking_id, NIBWP_EM_MAX_ROWS]
    );

    $out = [];

    foreach ($rows as $row) {
        $out[] = [
            'ticket_id' => (int) ($row['ticket_id'] ?? 0),
            'ticket_name' => (string) ($row['ticket_name'] ?? ''),
            'spaces' => (int) ($row['ticket_booking_spaces'] ?? 0),
            'price' => (float) ($row['ticket_booking_price'] ?? 0),
        ];
    }

    return $out;
}

/**
 * count_bookings — numbers over a period, which is usually the real question.
 *
 * Grouped in SQL rather than by hydrating bookings, for the same reason the
 * listing is: a count cannot leak a person.
 */
function nibwp_em_count_bookings(array $input): array|WP_Error
{
    $owner_id = 0;
    $event_id = 0;

    if (($input['event_id'] ?? null) !== null) {
        $resolved = nibwp_em_resolve_event($input['event_id']);

        if ($resolved instanceof WP_Error) {
            return $resolved;
        }

        $owner_id = (int) $resolved['owner_id'];
        $event_id = (int) $resolved['event_id'];
    }

    $denied = nibwp_em_require_bookings(__('Counting bookings', 'nibwp'), $owner_id);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $start = nibwp_em_date($input['start_date'] ?? null);
    $end = nibwp_em_date($input['end_date'] ?? null);

    if (($input['start_date'] ?? null) !== null && $start === '') {
        return nibwp_em_err('bad_date', __('start_date must be a real date in YYYY-MM-DD form.', 'nibwp'));
    }

    if (($input['end_date'] ?? null) !== null && $end === '') {
        return nibwp_em_err('bad_date', __('end_date must be a real date in YYYY-MM-DD form.', 'nibwp'));
    }

    if ($start !== '' && $end !== '' && $end < $start) {
        return nibwp_em_err('bad_date', __('end_date is before start_date.', 'nibwp'));
    }

    $tables = nibwp_em_tables();

    if ($tables instanceof WP_Error) {
        return $tables;
    }

    $where = ['b.booking_id > %d'];
    $args = [0];

    if ($event_id > 0) {
        $where[] = 'b.event_id = %d';
        $args[] = $event_id;
    }

    if ($start !== '') {
        $where[] = 'b.booking_date >= %s';
        $args[] = $start . ' 00:00:00';
    }

    if ($end !== '') {
        $where[] = 'b.booking_date <= %s';
        $args[] = $end . ' 23:59:59';
    }

    $args[] = NIBWP_EM_MAX_ROWS;

    $rows = nibwp_em_results(
        'SELECT b.booking_status, COUNT(*) AS bookings, SUM(b.booking_spaces) AS spaces,'
        . ' SUM(b.booking_price) AS revenue'
        . ' FROM ' . $tables['bookings'] . ' b WHERE ' . implode(' AND ', $where)
        . ' GROUP BY b.booking_status ORDER BY b.booking_status ASC LIMIT %d',
        $args
    );

    $by_status = [];
    $bookings = 0;
    $spaces = 0;
    $revenue = 0.0;

    foreach ($rows as $row) {
        $label = nibwp_em_booking_status_label($row['booking_status'] ?? 0);
        $by_status[$label] = [
            'bookings' => (int) ($row['bookings'] ?? 0),
            'spaces' => (int) ($row['spaces'] ?? 0),
            'revenue' => (float) ($row['revenue'] ?? 0),
        ];
        $bookings += (int) ($row['bookings'] ?? 0);
        $spaces += (int) ($row['spaces'] ?? 0);
        $revenue += (float) ($row['revenue'] ?? 0);
    }

    return [
        'event_id' => $event_id,
        'start_date' => $start,
        'end_date' => $end,
        'bookings' => $bookings,
        'spaces' => $spaces,
        'revenue' => $revenue,
        'by_status' => $by_status,
    ];
}

/**
 * approve_booking / reject_booking — THIS EMAILS A REAL PERSON.
 *
 * Both go through EM_Booking::approve() / ::reject() (classes/em-booking.php:1278,
 * :1290), which set the status and fire em_booking_status_changed — and that is
 * what sends the plugin's own confirmation or rejection mail to the address on the
 * booking. Default true because that is what the plugin does on its own screens
 * and a silent approval leaves somebody not knowing they have a place; send_email
 * false exists for when the person asked for it to be quiet.
 */
function nibwp_em_decide_booking(string $decision, array $input): array|WP_Error
{
    $resolved = nibwp_em_resolve_booking($input['booking_id'] ?? null);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $denied = nibwp_em_require_bookings(
        $decision === 'approve' ? __('Approving this booking', 'nibwp') : __('Rejecting this booking', 'nibwp'),
        (int) $resolved['owner_id']
    );

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $before = nibwp_em_booking_status_label($resolved['row']['booking_status'] ?? 0);

    // Only a booking that is waiting on a decision is one to decide. Re-approving
    // an approved booking would send a second confirmation to somebody who
    // already has one.
    $decidable = ['pending', 'waitlist', 'awaiting_payment', 'awaiting_online_payment'];

    if (!in_array($before, $decidable, true)) {
        return nibwp_em_err(
            'not_pending',
            sprintf(
                /* translators: 1: booking ID, 2: current status */
                __('Booking %1$d is "%2$s", not awaiting a decision. Refusing rather than emailing the person again.', 'nibwp'),
                (int) $resolved['booking_id'],
                $before
            ),
            409
        );
    }

    $send_email = array_key_exists('send_email', $input) ? nibwp_em_truthy($input['send_email']) : true;
    $reason = sanitize_text_field(is_scalar($input['reason'] ?? null) ? (string) $input['reason'] : '');

    if (!class_exists('EM_Booking')) {
        return nibwp_em_err(
            'plugin_api_missing',
            __('Events Manager did not load its EM_Booking class, so the booking cannot be decided.', 'nibwp'),
            503
        );
    }

    $booking_id = (int) $resolved['booking_id'];
    $booking = new EM_Booking($booking_id);

    if ((int) ($booking->booking_id ?? 0) !== $booking_id) {
        return nibwp_em_err(
            'load_failed',
            sprintf(
                /* translators: %d: booking ID */
                __('Events Manager could not load booking %d, so nothing was changed and no mail was sent.', 'nibwp'),
                $booking_id
            ),
            500
        );
    }

    if ($reason !== '' && method_exists($booking, 'add_note')) {
        // A note on the booking rather than in our response, so the reason is
        // where the next person to look at this booking will find it.
        $booking->add_note($reason);
    }

    $method = $decision === 'approve' ? 'approve' : 'reject';

    if (!method_exists($booking, $method)) {
        return nibwp_em_err(
            'plugin_api_missing',
            __('This build of Events Manager cannot approve or reject a booking through its own API.', 'nibwp'),
            503
        );
    }

    $done = $decision === 'approve'
        ? $booking->approve($send_email, false)
        : $booking->reject($send_email);

    if (!$done) {
        return nibwp_em_err(
            'status_change_failed',
            sprintf(
                /* translators: %s: the reason the plugin gave */
                __('Events Manager refused to change the booking status: %s', 'nibwp'),
                nibwp_em_plugin_errors($booking)
            ),
            422
        );
    }

    $after = nibwp_em_resolve_booking($booking_id);

    return [
        'updated' => true,
        'booking_id' => $booking_id,
        'event_id' => (int) $resolved['event_id'],
        'status_before' => $before,
        'status_after' => $after instanceof WP_Error
            ? ($decision === 'approve' ? 'approved' : 'rejected')
            : nibwp_em_booking_status_label($after['row']['booking_status'] ?? 0),
        'note_added' => $reason !== '',
        'email_sent' => $send_email,
        'email_notice' => $send_email
            ? __('Events Manager has emailed the person who made this booking, using its own template. Tell the user that mail went out.', 'nibwp')
            : __('No mail was sent, so the person does not yet know their booking changed.', 'nibwp'),
    ];
}

/** list_tickets — the tickets on one event, through the plugin's own ordering. */
function nibwp_em_list_tickets(array $input): array|WP_Error
{
    $resolved = nibwp_em_resolve_event($input['event_id'] ?? null);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    // Tickets are the shape of the booking offer, so they take the booking
    // capability rather than the event one.
    $denied = nibwp_em_require_bookings(__('Listing the tickets on this event', 'nibwp'), (int) $resolved['owner_id']);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $limit = nibwp_em_clamp($input['limit'] ?? null, NIBWP_EM_DEFAULT_ROWS, NIBWP_EM_MAX_ROWS);
    $event_id = (int) $resolved['event_id'];
    $tables = nibwp_em_tables();

    if ($tables instanceof WP_Error) {
        return $tables;
    }

    $rows = nibwp_em_results(
        'SELECT ticket_id, event_id, ticket_name, ticket_description, ticket_status, ticket_price,'
        . ' ticket_start, ticket_end, ticket_min, ticket_max, ticket_spaces, ticket_members, ticket_guests,'
        . ' ticket_required, ticket_order'
        . ' FROM ' . $tables['tickets'] . ' WHERE event_id = %d ORDER BY ticket_order ASC, ticket_id ASC LIMIT %d',
        [$event_id, $limit]
    );

    $tickets = [];

    foreach ($rows as $row) {
        $tickets[] = [
            'ticket_id' => (int) ($row['ticket_id'] ?? 0),
            'name' => (string) ($row['ticket_name'] ?? ''),
            'description' => (string) ($row['ticket_description'] ?? ''),
            'price' => (float) ($row['ticket_price'] ?? 0),
            'spaces' => (int) ($row['ticket_spaces'] ?? 0),
            'min' => (int) ($row['ticket_min'] ?? 0),
            'max' => (int) ($row['ticket_max'] ?? 0),
            'available_from' => (string) ($row['ticket_start'] ?? ''),
            'available_until' => (string) ($row['ticket_end'] ?? ''),
            'members_only' => !empty($row['ticket_members']),
            'guests_allowed' => !empty($row['ticket_guests']),
            'required' => !empty($row['ticket_required']),
            'status' => $row['ticket_status'] === null ? 'inactive' : 'active',
        ];
    }

    return [
        'event_id' => $event_id,
        'tickets' => $tickets,
        'count' => count($tickets),
        'limit' => $limit,
    ];
}

/**
 * ticket_availability — how many places are left on one ticket.
 *
 * Through EM_Ticket, because the answer is not a column: the plugin works it out
 * from the ticket's own spaces, the event's spaces and which booking statuses
 * count as taken (EM_Ticket::get_available_spaces(), classes/em-ticket.php:765).
 * Reimplementing that arithmetic here would disagree with the booking form the
 * first time somebody changed a setting.
 */
function nibwp_em_ticket_availability(array $input): array|WP_Error
{
    $resolved = nibwp_em_resolve_event($input['event_id'] ?? null);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $denied = nibwp_em_require_bookings(__('Reading ticket availability on this event', 'nibwp'), (int) $resolved['owner_id']);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $event_id = (int) $resolved['event_id'];
    $ticket_id = is_numeric($input['ticket_id'] ?? null) ? (int) $input['ticket_id'] : 0;

    if ($ticket_id <= 0) {
        return nibwp_em_err('bad_request', __('A positive ticket_id is required.', 'nibwp'));
    }

    $tables = nibwp_em_tables();

    if ($tables instanceof WP_Error) {
        return $tables;
    }

    $row = nibwp_em_row(
        'SELECT ticket_id, event_id, ticket_name, ticket_spaces, ticket_price'
        . ' FROM ' . $tables['tickets'] . ' WHERE ticket_id = %d LIMIT %d',
        [$ticket_id, 1]
    );

    if ($row === null) {
        return nibwp_em_err(
            'not_a_ticket',
            sprintf(
                /* translators: %d: ticket ID */
                __('No Events Manager ticket row has the id %d.', 'nibwp'),
                $ticket_id
            ),
            404
        );
    }

    // A ticket id from one event and an event id from another is how a caller
    // reads a ticket it has no authority over, since the capability was checked
    // against the EVENT's owner.
    if ((int) ($row['event_id'] ?? 0) !== $event_id) {
        return nibwp_em_err(
            'ticket_mismatch',
            sprintf(
                /* translators: 1: ticket ID, 2: event ID */
                __('Ticket %1$d does not belong to event %2$d.', 'nibwp'),
                $ticket_id,
                $event_id
            ),
            409
        );
    }

    $available = null;
    $booked = null;
    $is_available = null;

    if (class_exists('EM_Ticket')) {
        $ticket = new EM_Ticket($ticket_id);

        if (method_exists($ticket, 'get_available_spaces')) {
            $available = (int) $ticket->get_available_spaces();
        }

        if (method_exists($ticket, 'get_booked_spaces')) {
            $booked = (int) $ticket->get_booked_spaces();
        }

        if (method_exists($ticket, 'is_available')) {
            $is_available = (bool) $ticket->is_available();
        }
    }

    return [
        'event_id' => $event_id,
        'ticket_id' => $ticket_id,
        'ticket_name' => (string) ($row['ticket_name'] ?? ''),
        'price' => (float) ($row['ticket_price'] ?? 0),
        'spaces' => (int) ($row['ticket_spaces'] ?? 0),
        'booked_spaces' => $booked,
        'available_spaces' => $available,
        'on_sale' => $is_available,
    ];
}

/* ------------------------------------------------------------------ posture */

/**
 * Option names whose VALUE may be reported, and what each one is.
 *
 * Short on purpose: these are the switches somebody is asking about when they ask
 * how bookings behave on this site. dbem_* is one flat key space that grows every
 * release, so an allow-list is the only version of this that does not leak the
 * next key the plugin adds.
 *
 * @return array<string, string>
 */
function nibwp_em_public_settings(): array
{
    return [
        'dbem_bookings_approval' => 'bool',
        'dbem_bookings_approval_reserved' => 'bool',
        'dbem_bookings_approval_overbooking' => 'bool',
        'dbem_bookings_double' => 'bool',
        'dbem_bookings_user_cancellation' => 'bool',
        'dbem_bookings_currency' => 'string',
        'dbem_bookings_tickets_single' => 'bool',
        'dbem_bookings_registration_disable' => 'bool',
        'dbem_locations_enabled' => 'bool',
        'dbem_require_location' => 'bool',
        'dbem_categories_enabled' => 'bool',
        'dbem_tags_enabled' => 'bool',
        'dbem_timezone_enabled' => 'bool',
        'dbem_event_status_enabled' => 'bool',
        'dbem_events_default_scope' => 'string',
        'dbem_events_default_orderby' => 'string',
        'dbem_editor' => 'string',
        'dbem_version' => 'string',
        'timezone_string' => 'string',
    ];
}

/**
 * Option names that are reported as a NAME and whether they are set, never as a
 * value.
 *
 * The SMTP password and the Maps browser key are the ones this build has
 * (em-emails.php, the Maps settings tab); the licence key is dbem_pro_api_key.
 * Any other option whose name reads like a credential is caught by the pattern in
 * nibwp_em_is_secret_name(), so a key a later version adds does not default to
 * travelling.
 *
 * @return array<int, string>
 */
function nibwp_em_secret_settings(): array
{
    return [
        'dbem_smtp_host',
        'dbem_smtp_username',
        'dbem_smtp_password',
        'dbem_google_maps_browser_key',
        'dbem_pro_api_key',
    ];
}

/** Does this option name read like a credential? */
function nibwp_em_is_secret_name(string $name): bool
{
    return (bool) preg_match('/(key|secret|password|passwd|token|salt|credential|signature|private)/i', $name);
}

/**
 * get_settings — how bookings and the calendar are configured, minus the secrets.
 */
function nibwp_em_get_settings(): array|WP_Error
{
    $denied = nibwp_em_require(__('Reading the Events Manager settings', 'nibwp'), NIBWP_EM_CAP_SETTINGS);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $settings = [];
    $dropped = [];

    foreach (nibwp_em_public_settings() as $name => $as) {
        if (nibwp_em_is_secret_name($name)) {
            // Belt and braces: an allow-list entry whose name reads like a
            // credential is a mistake in the allow-list, and it loses.
            $dropped[] = $name;
            continue;
        }

        $raw = get_option($name, null);
        $value = nibwp_em_scalar($raw);

        if ($value === null) {
            // Dropped rather than cast. (string) on an array is "Array" and on an
            // object runs __toString(), which is how something nobody allow-listed
            // leaves the site.
            if ($raw !== null) {
                $dropped[] = $name;
            }

            continue;
        }

        if ($as === 'bool') {
            $settings[$name] = nibwp_em_truthy($value);
        } elseif ($as === 'int') {
            $settings[$name] = (int) $value;
        } else {
            $settings[$name] = (string) $value;
        }
    }

    $secrets = [];

    foreach (nibwp_em_secret_settings() as $name) {
        $raw = get_option($name, '');
        $secrets[$name] = ['is_set' => !(is_string($raw) ? trim($raw) === '' : empty($raw))];
    }

    return [
        'version' => defined('EM_VERSION') ? (string) EM_VERSION : '',
        'settings' => $settings,
        'secrets' => $secrets,
        'dropped' => $dropped,
        'note' => __('Values are reported only for an allow-list of switches. Credentials are reported as a name and whether they are set, never as a value.', 'nibwp'),
    ];
}

/* ------------------------------------------------------------------ audits */

/**
 * Today, in the site's own timezone.
 *
 * Audits compare against the site's idea of "already happened", not the server's.
 */
function nibwp_em_today(): string
{
    if (function_exists('current_time')) {
        return (string) current_time('Y-m-d');
    }

    return gmdate('Y-m-d');
}

/**
 * The placeholder list for the event post types, and the types to fill it with.
 *
 * Generated from a count of our own array, never from anything a caller sent —
 * which is the only way a variable is allowed into one of these query strings.
 *
 * @return array{types: array<int, string>, placeholders: string}
 */
function nibwp_em_post_type_placeholders(): array
{
    $types = nibwp_em_event_post_types();

    return ['types' => $types, 'placeholders' => implode(', ', array_fill(0, count($types), '%s'))];
}

/**
 * audit_post_table_mismatch — the failure this plugin's design makes possible.
 *
 * Four shapes of it, because they have four different fixes:
 *   post_missing      the row points at a post id that is not there any more.
 *   post_wrong_type   the row points at something that is not an event — a page,
 *                     for instance. The event then edits whatever that is.
 *   status_disagrees  the row says published and the post says draft, or the
 *                     reverse. One of the two screens shows the event; the other
 *                     does not, and neither is lying.
 *   row_without_post  a row with no post that is not a recurrence, which is the
 *                     only kind EM creates deliberately (classes/em-event.php:493).
 *   post_without_row  an event post the row was never written for. It appears in
 *                     wp-admin and on no calendar at all.
 */
function nibwp_em_audit_post_table_mismatch(array $input): array|WP_Error
{
    $denied = nibwp_em_require(__('Auditing events across both stores', 'nibwp'), NIBWP_EM_CAP_EVENTS_OTHERS);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    return nibwp_em_mismatch_findings(nibwp_em_clamp($input['limit'] ?? null, NIBWP_EM_DEFAULT_ROWS, NIBWP_EM_MAX_ROWS));
}

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_em_mismatch_findings(int $limit): array|WP_Error
{
    global $wpdb;

    $tables = nibwp_em_tables();

    if ($tables instanceof WP_Error) {
        return $tables;
    }

    if (!isset($wpdb) || !is_object($wpdb)) {
        return nibwp_em_err('no_database', __('No database connection is available.', 'nibwp'), 503);
    }

    $posts = (string) $wpdb->posts;
    $types = nibwp_em_post_type_placeholders();
    $findings = [];

    $broken = nibwp_em_results(
        'SELECT e.event_id, e.post_id, e.event_name, e.event_status, p.ID AS wp_id, p.post_type, p.post_status'
        . ' FROM ' . $tables['events'] . ' e LEFT JOIN ' . $posts . ' p ON p.ID = e.post_id'
        . ' WHERE e.post_id IS NOT NULL AND (p.ID IS NULL OR p.post_type NOT IN (' . $types['placeholders'] . '))'
        . ' ORDER BY e.event_id ASC LIMIT %d',
        array_merge($types['types'], [$limit])
    );

    foreach ($broken as $row) {
        $findings[] = [
            'problem' => ($row['wp_id'] ?? null) === null ? 'post_missing' : 'post_wrong_type',
            'event_id' => (int) ($row['event_id'] ?? 0),
            'post_id' => (int) ($row['post_id'] ?? 0),
            'event_name' => (string) ($row['event_name'] ?? ''),
            'post_type' => (string) ($row['post_type'] ?? ''),
        ];
    }

    // The status comparison is written as a CASE rather than an OR chain because
    // event_status is NULLable: `event_status = 1` is NULL for a draft row, and a
    // NULL in a NOT(...) silently drops the row instead of reporting it.
    $disagree = nibwp_em_results(
        'SELECT e.event_id, e.post_id, e.event_name, e.event_status, p.post_status'
        . ' FROM ' . $tables['events'] . ' e INNER JOIN ' . $posts . ' p ON p.ID = e.post_id'
        . ' WHERE p.post_type IN (' . $types['placeholders'] . ') AND NOT (CASE'
        . ' WHEN e.event_status IS NULL THEN p.post_status IN (%s, %s)'
        . ' WHEN e.event_status = 1 THEN p.post_status IN (%s, %s)'
        . ' WHEN e.event_status = 0 THEN p.post_status = %s'
        . ' WHEN e.event_status = -1 THEN p.post_status = %s'
        . ' ELSE 0 END)'
        . ' ORDER BY e.event_id ASC LIMIT %d',
        array_merge($types['types'], ['draft', 'auto-draft', 'publish', 'private', 'pending', 'trash', $limit])
    );

    foreach ($disagree as $row) {
        $findings[] = [
            'problem' => 'status_disagrees',
            'event_id' => (int) ($row['event_id'] ?? 0),
            'post_id' => (int) ($row['post_id'] ?? 0),
            'event_name' => (string) ($row['event_name'] ?? ''),
            'row_status' => nibwp_em_event_status_label($row['event_status'] ?? null),
            'post_status' => (string) ($row['post_status'] ?? ''),
        ];
    }

    $orphan_rows = nibwp_em_results(
        'SELECT event_id, event_name, event_type, event_status FROM ' . $tables['events']
        . ' WHERE post_id IS NULL AND recurrence_set_id IS NULL AND (event_type IS NULL OR event_type <> %s)'
        . ' ORDER BY event_id ASC LIMIT %d',
        ['recurrence', $limit]
    );

    foreach ($orphan_rows as $row) {
        $findings[] = [
            'problem' => 'row_without_post',
            'event_id' => (int) ($row['event_id'] ?? 0),
            'event_name' => (string) ($row['event_name'] ?? ''),
            'event_type' => (string) ($row['event_type'] ?? ''),
        ];
    }

    $orphan_posts = nibwp_em_results(
        'SELECT p.ID, p.post_type, p.post_title, p.post_status'
        . ' FROM ' . $posts . ' p LEFT JOIN ' . $tables['events'] . ' e ON e.post_id = p.ID'
        . ' WHERE p.post_type IN (' . $types['placeholders'] . ') AND p.post_status NOT IN (%s, %s)'
        . ' AND e.event_id IS NULL ORDER BY p.ID ASC LIMIT %d',
        array_merge($types['types'], ['auto-draft', 'inherit', $limit])
    );

    foreach ($orphan_posts as $row) {
        $findings[] = [
            'problem' => 'post_without_row',
            'event_id' => 0,
            'post_id' => (int) ($row['ID'] ?? 0),
            'event_name' => (string) ($row['post_title'] ?? ''),
            'post_type' => (string) ($row['post_type'] ?? ''),
            'post_status' => (string) ($row['post_status'] ?? ''),
        ];
    }

    return [
        'findings' => $findings,
        'count' => count($findings),
        'limit' => $limit,
        'checked' => ['post_missing', 'post_wrong_type', 'status_disagrees', 'row_without_post', 'post_without_row'],
        'note' => __('Each of these means the event behaves differently in wp-admin than on the calendar. Re-saving the event through update_event rewrites both stores.', 'nibwp'),
    ];
}

/** audit_events_without_location — and events pointing at a location that is gone. */
function nibwp_em_audit_events_without_location(array $input): array|WP_Error
{
    $denied = nibwp_em_require(__('Auditing event locations', 'nibwp'), NIBWP_EM_CAP_EVENTS_OTHERS);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    return nibwp_em_location_findings(nibwp_em_clamp($input['limit'] ?? null, NIBWP_EM_DEFAULT_ROWS, NIBWP_EM_MAX_ROWS));
}

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_em_location_findings(int $limit): array|WP_Error
{
    $tables = nibwp_em_tables();

    if ($tables instanceof WP_Error) {
        return $tables;
    }

    $rows = nibwp_em_results(
        'SELECT e.event_id, e.event_name, e.location_id, e.event_start_date, e.event_status, l.location_id AS found'
        . ' FROM ' . $tables['events'] . ' e LEFT JOIN ' . $tables['locations'] . ' l ON l.location_id = e.location_id'
        . ' WHERE (e.event_type IS NULL OR e.event_type <> %s)'
        . ' AND (e.location_id IS NULL OR e.location_id = %d OR l.location_id IS NULL)'
        . ' ORDER BY e.event_start_date DESC LIMIT %d',
        ['recurrence', 0, $limit]
    );

    $findings = [];

    foreach ($rows as $row) {
        $claimed = (int) ($row['location_id'] ?? 0);
        $findings[] = [
            'problem' => $claimed > 0 ? 'location_missing' : 'no_location',
            'event_id' => (int) ($row['event_id'] ?? 0),
            'event_name' => (string) ($row['event_name'] ?? ''),
            'location_id' => $claimed,
            'start_date' => (string) ($row['event_start_date'] ?? ''),
            'status' => nibwp_em_event_status_label($row['event_status'] ?? null),
        ];
    }

    return [
        'findings' => $findings,
        'count' => count($findings),
        'limit' => $limit,
        'checked' => ['no_location', 'location_missing'],
    ];
}

/** audit_event_dates — an event that ends before it starts, in either date pair. */
function nibwp_em_audit_event_dates(array $input): array|WP_Error
{
    $denied = nibwp_em_require(__('Auditing event dates', 'nibwp'), NIBWP_EM_CAP_EVENTS_OTHERS);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    return nibwp_em_date_findings(nibwp_em_clamp($input['limit'] ?? null, NIBWP_EM_DEFAULT_ROWS, NIBWP_EM_MAX_ROWS));
}

/**
 * Both pairs are checked, because they can disagree with each other as well as
 * with themselves: the local pair is what the editor shows and the UTC pair is
 * what the listings query (classes/em-event.php:3910-3911), so an event can read
 * correctly on the edit screen and still sort before its own start date.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_em_date_findings(int $limit): array|WP_Error
{
    $tables = nibwp_em_tables();

    if ($tables instanceof WP_Error) {
        return $tables;
    }

    $rows = nibwp_em_results(
        'SELECT event_id, event_name, event_start_date, event_start_time, event_end_date, event_end_time,'
        . ' event_start, event_end, event_timezone, event_all_day'
        . ' FROM ' . $tables['events'] . ' WHERE'
        . ' (event_start_date IS NOT NULL AND event_end_date IS NOT NULL AND ('
        . ' event_end_date < event_start_date'
        . ' OR (event_end_date = event_start_date AND event_start_time IS NOT NULL AND event_end_time IS NOT NULL'
        . ' AND event_end_time < event_start_time)))'
        . ' OR (event_start IS NOT NULL AND event_end IS NOT NULL AND event_end < event_start)'
        . ' ORDER BY event_id ASC LIMIT %d',
        [$limit]
    );

    $findings = [];

    foreach ($rows as $row) {
        $local_start = trim((string) ($row['event_start_date'] ?? '') . ' ' . (string) ($row['event_start_time'] ?? ''));
        $local_end = trim((string) ($row['event_end_date'] ?? '') . ' ' . (string) ($row['event_end_time'] ?? ''));
        $utc_start = (string) ($row['event_start'] ?? '');
        $utc_end = (string) ($row['event_end'] ?? '');

        $where = [];

        if ($local_start !== '' && $local_end !== '' && $local_end < $local_start) {
            $where[] = 'local';
        }

        if ($utc_start !== '' && $utc_end !== '' && $utc_end < $utc_start) {
            $where[] = 'utc';
        }

        $findings[] = [
            'problem' => 'end_before_start',
            'event_id' => (int) ($row['event_id'] ?? 0),
            'event_name' => (string) ($row['event_name'] ?? ''),
            'in' => $where,
            'start_local' => $local_start,
            'end_local' => $local_end,
            'start_utc' => $utc_start,
            'end_utc' => $utc_end,
            'timezone' => (string) ($row['event_timezone'] ?? ''),
            'all_day' => !empty($row['event_all_day']),
        ];
    }

    return [
        'findings' => $findings,
        'count' => count($findings),
        'limit' => $limit,
        'checked' => ['end_before_start'],
    ];
}

/**
 * audit_bookings — two findings, both of which cost somebody money or a seat.
 *
 *   pending_past_event  a booking still waiting on a decision after the event has
 *                       already happened. Somebody never got an answer.
 *   at_capacity_open    an event whose places are all taken and whose booking form
 *                       is still open, which keeps taking bookings nobody can honour.
 *
 * Takes manage_others_bookings, because it spans every event on the site.
 */
function nibwp_em_audit_bookings(array $input): array|WP_Error
{
    $denied = nibwp_em_require(__('Auditing bookings across every event', 'nibwp'), NIBWP_EM_CAP_BOOKINGS_OTHERS);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    return nibwp_em_booking_findings(nibwp_em_clamp($input['limit'] ?? null, NIBWP_EM_DEFAULT_ROWS, NIBWP_EM_MAX_ROWS));
}

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_em_booking_findings(int $limit): array|WP_Error
{
    $tables = nibwp_em_tables();

    if ($tables instanceof WP_Error) {
        return $tables;
    }

    $today = nibwp_em_today();
    $findings = [];

    // Statuses 0 and 6 are Pending and Waitlist — the two that are waiting on
    // somebody (classes/em-booking.php:202-212). Not one contact column is
    // selected; a finding names the booking, never the person.
    $stale = nibwp_em_results(
        'SELECT b.booking_id, b.event_id, b.booking_spaces, b.booking_status, b.booking_date,'
        . ' e.event_name, e.event_end_date'
        . ' FROM ' . $tables['bookings'] . ' b INNER JOIN ' . $tables['events'] . ' e ON e.event_id = b.event_id'
        . ' WHERE b.booking_status IN (%d, %d) AND e.event_end_date IS NOT NULL AND e.event_end_date < %s'
        . ' ORDER BY e.event_end_date ASC LIMIT %d',
        [0, 6, $today, $limit]
    );

    foreach ($stale as $row) {
        $findings[] = [
            'problem' => 'pending_past_event',
            'booking_id' => (int) ($row['booking_id'] ?? 0),
            'event_id' => (int) ($row['event_id'] ?? 0),
            'event_name' => (string) ($row['event_name'] ?? ''),
            'event_ended' => (string) ($row['event_end_date'] ?? ''),
            'spaces' => (int) ($row['booking_spaces'] ?? 0),
            'status' => nibwp_em_booking_status_label($row['booking_status'] ?? 0),
        ];
    }

    // Statuses 1 and 5 are Approved and Awaiting Payment, the two that hold a
    // place. event_rsvp = 1 is the plugin's "bookings are open on this event".
    $full = nibwp_em_results(
        'SELECT e.event_id, e.event_name, e.event_spaces, e.event_end_date,'
        . ' COALESCE(SUM(b.booking_spaces), 0) AS booked'
        . ' FROM ' . $tables['events'] . ' e LEFT JOIN ' . $tables['bookings'] . ' b'
        . ' ON b.event_id = e.event_id AND b.booking_status IN (%d, %d)'
        . ' WHERE e.event_rsvp = %d AND e.event_spaces > %d'
        . ' AND (e.event_end_date IS NULL OR e.event_end_date >= %s)'
        . ' GROUP BY e.event_id, e.event_name, e.event_spaces, e.event_end_date'
        . ' HAVING booked >= e.event_spaces ORDER BY e.event_id ASC LIMIT %d',
        [1, 5, 1, 0, $today, $limit]
    );

    foreach ($full as $row) {
        $findings[] = [
            'problem' => 'at_capacity_open',
            'event_id' => (int) ($row['event_id'] ?? 0),
            'event_name' => (string) ($row['event_name'] ?? ''),
            'spaces' => (int) ($row['event_spaces'] ?? 0),
            'booked_spaces' => (int) ($row['booked'] ?? 0),
            'event_end_date' => (string) ($row['event_end_date'] ?? ''),
        ];
    }

    return [
        'findings' => $findings,
        'count' => count($findings),
        'limit' => $limit,
        'checked' => ['pending_past_event', 'at_capacity_open'],
        'contact_details_withheld' => __('A finding names the booking and the event, never the person who made it.', 'nibwp'),
    ];
}

/**
 * audit_calendar — all five checks in one answer.
 *
 * Takes both capabilities up front, because it reports both event findings and
 * booking findings and a half-answer labelled "clean" is worse than a refusal.
 */
function nibwp_em_audit_calendar(array $input): array|WP_Error
{
    $denied = nibwp_em_require(__('Auditing the whole calendar', 'nibwp'), NIBWP_EM_CAP_EVENTS_OTHERS);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $denied = nibwp_em_require_bookings(__('Auditing the whole calendar, which reports booking findings', 'nibwp'), 0);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $limit = nibwp_em_clamp($input['limit'] ?? null, NIBWP_EM_DEFAULT_ROWS, NIBWP_EM_MAX_ROWS);

    // Each check is given the same bound, so the worst case is five bounded
    // queries rather than one unbounded table scan.
    $sections = [
        'post_table_mismatch' => nibwp_em_mismatch_findings($limit),
        'events_without_location' => nibwp_em_location_findings($limit),
        'event_dates' => nibwp_em_date_findings($limit),
        'bookings' => nibwp_em_booking_findings($limit),
    ];

    $out = [];
    $total = 0;

    foreach ($sections as $name => $section) {
        if ($section instanceof WP_Error) {
            return $section;
        }

        $out[$name] = $section;
        $total += (int) $section['count'];
    }

    return [
        'sections' => $out,
        'findings_total' => $total,
        'limit' => $limit,
        'clean' => $total === 0,
    ];
}
