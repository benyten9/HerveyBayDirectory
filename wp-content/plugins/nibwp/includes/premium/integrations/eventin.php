<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: Eventin (wp-event-solution).
 *
 * Verified against Eventin 4.1.25 (eventin.php:13, and Wpeventin::version() at
 * eventin.php:46 which is what this file guards on).
 *
 * WHY THE DATE CONTRACT IS THE WHOLE STORY. Eventin does not store a timestamp.
 * It stores an event as five separate meta values that only mean something
 * together — `etn_start_date`, `etn_end_date`, `etn_start_time`, `etn_end_time`
 * and `event_timezone` (core/event/event-model.php:37-41) — and the archive and
 * calendar queries compare the two DATE keys as strings and as MySQL DATEs
 * against `gmdate('Y-m-d')` (utils/helper.php:2181-2188 for the string compare,
 * utils/helper.php:2965-2971 and 2975-2983 for `'type' => 'DATE'`). So a start
 * date written as `15/06/2026` or `06-15-2026` casts to NULL inside MySQL, drops
 * out of every BETWEEN and every `>=`, and produces an event that exists, lists
 * in wp-admin, and never appears on the calendar. `Y-m-d` is not a preference
 * here, it is the only shape that works, and this file refuses anything else
 * rather than guessing which number is the month.
 *
 * There is no all-day flag in Eventin. An all-day event is one with the two
 * DATE keys set and the two TIME keys empty: `etn_parse_event_datetime()` falls
 * back to midnight when the time is blank (utils/functions.php:501-508), and the
 * single-event template renders no time at all when the meta is empty
 * (utils/helper.php:1176-1177). So `all_day` here writes the dates and clears
 * the times, which is what the plugin's own readers expect.
 *
 * ATTENDEES ARE PEOPLE. Eventin keeps each attendee as an `etn-attendee` post
 * carrying their name, email and phone (core/Attendee/attendee-model.php:28-30),
 * and gates its own attendee screen and its whole REST controller on
 * `etn_manage_attendee` (core/Admin/Menu.php:162, core/Attendee/Api/AttendeeController.php:404,
 * 975, 1015, 1143, 1316). This file asks for the same capability and not a
 * weaker one: `edit_posts` is held by every Contributor on the site and says
 * nothing about whether somebody may read a stranger's phone number.
 *
 * CREDENTIALS. Eventin keeps its payment, mail and meeting keys in ONE flat
 * option array, `etn_event_options`, alongside harmless display settings —
 * `paypal_client_secret`, `stripe_live_secret_key`, `etn_stripe_webhook_secret`,
 * `zoom_client_secret`, `google_meet_client_secret_key`, `google_token`,
 * `mail_mint_api` and more. Settings therefore leave here through an
 * ALLOW-LIST of fields whose value may travel; every other field is reported as
 * a name and whether it is set. An allow-list rather than a denylist because the
 * key space is flat and grows with each add-on, so a denylist would default to
 * leaking every key a later version introduces. Per attendee,
 * `etn_info_edit_token` is withheld the same way: it is a bearer token for an
 * unauthenticated ticket-edit URL (core/Attendee/attendee-model.php:38), not a
 * field anybody needs read back.
 *
 * A NON-SCALAR IS DROPPED, NEVER CAST. `(string) $array` is the word "Array" and
 * `(string) $object` either leaks `__toString()` or fatals; both hide what was
 * really in the meta behind something that reads as harmless. So the reader skips
 * a key whose value is not scalar and names it among the withheld, which is the
 * honest answer.
 *
 * AN ID FROM A CALLER IS A CLAIM. Every action whose SUBJECT is an id resolves
 * the post type FIRST, alone, before any capability is consulted —
 * `nibwp_eventin_resolve()`. Handed the id of a page, a delete refuses on the type
 * and must not have asked whether anybody may delete pages, because
 * `current_user_can('delete_post', $page_id)` answering yes is how an agent
 * deletes the About page while believing it cancelled an event. THEN the
 * capability, and only THEN the rest of the payload: an unauthorised caller who
 * learns which status values are legal by reading our refusals has been handed a
 * map of the site by the error message rather than by the data.
 *
 * Where an id is a FILTER rather than the subject — `list_attendees`,
 * `count_attendees` — the door capability comes first instead, so a caller with no
 * authority cannot use the filter to probe which ids exist. The two rules are not
 * in tension: the first exists because the capability that follows is asked ABOUT
 * the object, the second because no capability follows that would mention it.
 *
 * WHAT IS NOT HERE, AND WILL NOT BE: refunding or cancelling a paid ticket.
 * Money leaving a merchant account is not an agent's decision, and Eventin's own
 * refund service is one click away in the booking screen with the consequences in
 * front of the person. Also absent: creating, editing or deleting an attendee
 * record, editing an order, and issuing or voiding a coupon.
 */

/** The door for everything about events themselves (core/Admin/Menu.php:103). */
const NIBWP_EVENTIN_CAP_EVENT = 'etn_manage_event';

/** Speakers and organisers share one screen and one capability (core/Admin/Menu.php:115, core/speaker/Api/SpeakerController.php:162). */
const NIBWP_EVENTIN_CAP_ORGANIZER = 'etn_manage_organizer';

/** The agenda screen (core/Admin/Menu.php:121). */
const NIBWP_EVENTIN_CAP_SCHEDULE = 'etn_manage_schedule';

/** Bookings and money (core/Admin/Menu.php:109, core/Order/OrderController.php:1358). */
const NIBWP_EVENTIN_CAP_ORDER = 'etn_manage_order';

/** Personal data. Eventin's own gate, not a weaker stand-in (core/Admin/Menu.php:162). */
const NIBWP_EVENTIN_CAP_ATTENDEE = 'etn_manage_attendee';

/** How many rows any listing returns, whatever was asked for. */
const NIBWP_EVENTIN_MAX_ROWS = 200;

/** What a listing returns when the caller says nothing. */
const NIBWP_EVENTIN_DEFAULT_ROWS = 20;

/** The only date shape the archive and calendar queries can read. */
const NIBWP_EVENTIN_DATE_FORMAT = 'Y-m-d';

/** 24-hour, so no reader has to guess at a missing meridiem. */
const NIBWP_EVENTIN_TIME_FORMAT = 'H:i';

/**
 * Is Eventin actually loaded?
 *
 * `Wpeventin` is the main plugin class and `Wpeventin::version()` is its single
 * version source (eventin.php:27 and :46). `ETN_DEFAULT_TICKET_NAME` is defined
 * by the same constructor (eventin.php:101), so between them they separate "not
 * installed" from "installed and half-booted".
 */
function nibwp_eventin_guard(): ?WP_Error
{
    if (!class_exists('Wpeventin') && !defined('ETN_DEFAULT_TICKET_NAME')) {
        return new WP_Error(
            'nibwp_eventin_missing',
            __('Eventin is not active on this site.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/** The Eventin version, when it will tell us. */
function nibwp_eventin_version(): string
{
    if (!class_exists('Wpeventin') || !method_exists('Wpeventin', 'version')) {
        return '';
    }

    try {
        $version = Wpeventin::version();
    } catch (\Throwable $error) {
        return '';
    }

    return is_scalar($version) ? (string) $version : '';
}

/** A refusal about the request itself. */
function nibwp_eventin_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error('nibwp_eventin_' . $code, $message, ['status' => $status]);
}

/** A refusal about authority rather than about the request. */
function nibwp_eventin_forbidden(string $what, string $cap): WP_Error
{
    return new WP_Error(
        'nibwp_eventin_forbidden',
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
 * The door, asked before anything about the request is looked at.
 *
 * Order matters more than it looks. If validation ran first, a caller with no
 * authority at all would learn from our refusals which event ids exist and which
 * status names are legal — a free map of the site, handed over by the error
 * message rather than by the data.
 */
function nibwp_eventin_require(string $what, string $cap): ?WP_Error
{
    return current_user_can($cap) ? null : nibwp_eventin_forbidden($what, $cap);
}

/** May this account edit this one post? */
function nibwp_eventin_can_edit(int $id): bool
{
    return current_user_can('edit_post', $id);
}

/** May this account delete this one post? */
function nibwp_eventin_can_delete(int $id): bool
{
    return current_user_can('delete_post', $id);
}

/**
 * The two gates every event write passes, in that order.
 *
 * The door first — Eventin's own controller asks exactly this before it will let
 * anybody near an event (core/event/Api/EventController.php:335) — and then the
 * per-object check, because `etn_manage_event` says nothing about WHICH event.
 * Both raise the same error code, which is why the check pins each with an
 * inverse case rather than trusting that a refusal means the right gate fired.
 *
 * Called only AFTER the id has been proved to be an event, so `edit_post` and
 * `delete_post` are never asked about an object of the wrong type.
 */
function nibwp_eventin_require_event_write(int $id, string $what, bool $deleting): ?WP_Error
{
    $denied = nibwp_eventin_require($what, NIBWP_EVENTIN_CAP_EVENT);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    if ($deleting) {
        return nibwp_eventin_can_delete($id) ? null : nibwp_eventin_forbidden($what, 'delete_post');
    }

    return nibwp_eventin_can_edit($id) ? null : nibwp_eventin_forbidden($what, 'edit_post');
}

/**
 * The same two gates for a read of one post, door first.
 *
 * Separate from the write gate only so the refusal names the right thing: a
 * message that always blames `etn_manage_event` when the real failure was
 * `edit_post` for one post sends the owner to the wrong settings screen, and a
 * caller cannot tell which of the two to ask for.
 */
function nibwp_eventin_require_post_read(int $id, string $what, string $cap): ?WP_Error
{
    $denied = nibwp_eventin_require($what, $cap);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    return nibwp_eventin_can_edit($id) ? null : nibwp_eventin_forbidden($what, 'edit_post');
}

/** Post type names, as Eventin registers them. */
function nibwp_eventin_type(string $kind): string
{
    switch ($kind) {
        case 'event':
            // core/event/cpt.php:17 — a bare "etn", which is easy to mistype.
            return 'etn';
        case 'schedule':
            return 'etn-schedule';
        case 'attendee':
            return 'etn-attendee';
        case 'order':
            return 'etn-order';
    }

    return '';
}

/** Speakers and organisers are USERS with a role, not posts (core/speaker/user-model.php:21, core/speaker/speaker-role.php:57 and :78). */
function nibwp_eventin_role(string $kind): string
{
    return 'organizer' === $kind ? 'etn-organizer' : ('speaker' === $kind ? 'etn-speaker' : '');
}

/** Human names for refusal messages. */
function nibwp_eventin_kind_label(string $kind): string
{
    switch ($kind) {
        case 'schedule':
            return __('schedule', 'nibwp');
        case 'attendee':
            return __('attendee', 'nibwp');
        case 'speaker':
            return __('speaker', 'nibwp');
        case 'organizer':
            return __('organizer', 'nibwp');
    }

    return __('event', 'nibwp');
}

/**
 * Turn a caller's id into a post of the expected type, and do NOTHING else.
 *
 * No capability is consulted here, deliberately. Until the object is known to be
 * the kind the action is about, asking whether anybody may edit it is asking the
 * wrong question — and a `delete_post` check that passes on a page id is how an
 * agent deletes the About page while believing it cancelled an event.
 *
 * @return array{post:WP_Post}|WP_Error
 */
function nibwp_eventin_resolve(int $id, string $kind): array|WP_Error
{
    $expected = nibwp_eventin_type($kind);

    if ($id <= 0) {
        return nibwp_eventin_err(
            'bad_request',
            sprintf(
                /* translators: %s: the kind of record, e.g. event */
                __('A positive %s id is required.', 'nibwp'),
                nibwp_eventin_kind_label($kind)
            )
        );
    }

    $post = get_post($id);

    if (!$post instanceof WP_Post) {
        return nibwp_eventin_err(
            'no_such_post',
            sprintf(
                /* translators: %d: post ID */
                __('Nothing with the id %d exists on this site.', 'nibwp'),
                $id
            ),
            404
        );
    }

    if ($expected === '' || $post->post_type !== $expected) {
        // The code names the kind, so a caller that passed a page id learns the
        // problem is the TYPE and not its own permissions.
        $codes = [
            'event' => 'not_an_event',
            'schedule' => 'not_a_schedule',
            'attendee' => 'not_an_attendee',
            'order' => 'not_an_order',
        ];

        return nibwp_eventin_err(
            $codes[$kind] ?? 'not_an_event',
            sprintf(
                /* translators: 1: post ID, 2: actual post type, 3: expected post type */
                __('Post %1$d is a "%2$s", not a "%3$s". Refusing on the type before considering permissions.', 'nibwp'),
                $id,
                (string) $post->post_type,
                $expected
            ),
            409
        );
    }

    return ['post' => $post];
}

/**
 * The same claim-checking for a speaker or organiser, which Eventin stores as a
 * user carrying a role rather than as a post.
 *
 * @return array{user:WP_User}|WP_Error
 */
function nibwp_eventin_resolve_person(int $id, string $kind): array|WP_Error
{
    $role = nibwp_eventin_role($kind);

    if ($id <= 0) {
        return nibwp_eventin_err(
            'bad_request',
            sprintf(
                /* translators: %s: the kind of record, e.g. speaker */
                __('A positive %s id is required.', 'nibwp'),
                nibwp_eventin_kind_label($kind)
            )
        );
    }

    $user = get_userdata($id);

    if (!$user instanceof WP_User) {
        return nibwp_eventin_err(
            'no_such_user',
            sprintf(
                /* translators: %d: user ID */
                __('No user with the id %d exists on this site.', 'nibwp'),
                $id
            ),
            404
        );
    }

    $roles = is_array($user->roles ?? null) ? array_map('strval', $user->roles) : [];

    if ($role === '' || !in_array($role, $roles, true)) {
        return nibwp_eventin_err(
            'speaker' === $kind ? 'not_a_speaker' : 'not_an_organizer',
            sprintf(
                /* translators: 1: user ID, 2: expected role name */
                __('User %1$d does not hold the "%2$s" role. Refusing on the role before considering permissions.', 'nibwp'),
                $id,
                $role
            ),
            409
        );
    }

    return ['user' => $user];
}

/** Clamp a caller's count to something this site can afford to build. */
function nibwp_eventin_clamp(mixed $raw, int $default, int $max): int
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

/**
 * Every meta key that has to be written together for an event to be visible.
 *
 * Read off core/event/event-model.php:37-41, and confirmed by what actually
 * queries them: the archive sort and the "upcoming"/"expired" filters compare
 * `etn_start_date` and `etn_end_date` (utils/helper.php:2965-2990), and
 * `Event_Model::get_status()` reads all four date and time keys plus
 * `event_timezone` to decide whether an event is upcoming, ongoing or expired
 * (core/event/event-model.php:194-199).
 */
function nibwp_eventin_required_date_meta(): array
{
    return ['etn_start_date', 'etn_end_date', 'etn_start_time', 'etn_end_time', 'event_timezone'];
}

/**
 * The date formats a caller may use, in the order they are tried.
 *
 * Short on purpose. Every one is unambiguous about which number is the month,
 * which is the single mistake that puts an event six months away without anybody
 * noticing.
 */
function nibwp_eventin_date_formats(): array
{
    return ['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i', 'Y-m-d'];
}

/**
 * Parse a caller's date strictly, or refuse by name.
 *
 * `createFromFormat()` is not strict on its own: it rolls 2026-02-31 into March.
 * So the result is formatted back and compared with the input, and anything that
 * does not survive the round trip is refused. The leading `!` zeroes the fields
 * the format does not mention, so a bare date means midnight rather than
 * whatever o'clock it happens to be.
 *
 * @return array{date:string,time:string,date_only:bool}|WP_Error
 */
function nibwp_eventin_parse_datetime(mixed $raw, string $field, DateTimeZone $tz): array|WP_Error
{
    if (!is_string($raw) && !is_numeric($raw)) {
        return nibwp_eventin_err(
            'bad_date',
            sprintf(
                /* translators: 1: input field name, 2: list of accepted formats */
                __('%1$s must be a date string. Accepted formats: %2$s.', 'nibwp'),
                $field,
                implode(', ', nibwp_eventin_date_formats())
            )
        );
    }

    $text = trim((string) $raw);

    if ($text === '') {
        return nibwp_eventin_err(
            'bad_date',
            sprintf(
                /* translators: %s: input field name */
                __('%s is empty.', 'nibwp'),
                $field
            )
        );
    }

    foreach (nibwp_eventin_date_formats() as $format) {
        $parsed = DateTimeImmutable::createFromFormat('!' . $format, $text, $tz);

        if ($parsed instanceof DateTimeImmutable && $parsed->format($format) === $text) {
            return [
                'date' => $parsed->format(NIBWP_EVENTIN_DATE_FORMAT),
                'time' => $parsed->format(NIBWP_EVENTIN_TIME_FORMAT),
                'date_only' => NIBWP_EVENTIN_DATE_FORMAT === $format,
            ];
        }
    }

    return nibwp_eventin_err(
        'bad_date',
        sprintf(
            /* translators: 1: input field name, 2: the value received, 3: list of accepted formats */
            __('%1$s is not a date this integration will guess at: "%2$s". Accepted formats: %3$s. A date written any other way stores as a value MySQL cannot cast, and the event then never appears on the calendar.', 'nibwp'),
            $field,
            $text,
            implode(', ', nibwp_eventin_date_formats())
        )
    );
}

/**
 * Resolve the timezone an event's dates are written in.
 *
 * Eventin stores a name in `event_timezone` and falls back to the site's when it
 * is empty (core/event/event-model.php:198), so that is the order used here too.
 *
 * @return array{name:string,object:DateTimeZone}|WP_Error
 */
function nibwp_eventin_timezone(mixed $raw, ?int $event_id): array|WP_Error
{
    $candidates = [];
    $explicit = is_string($raw) && trim($raw) !== '';

    if ($explicit) {
        $candidates[] = trim((string) $raw);
    } elseif ($event_id !== null && $event_id > 0) {
        $stored = get_post_meta($event_id, 'event_timezone', true);

        if (is_string($stored) && trim($stored) !== '') {
            $candidates[] = trim($stored);
        }
    }

    if (function_exists('wp_timezone_string')) {
        $site = wp_timezone_string();

        if (is_string($site) && $site !== '') {
            $candidates[] = $site;
        }
    }

    $candidates[] = 'UTC';

    foreach ($candidates as $name) {
        try {
            return ['name' => $name, 'object' => new DateTimeZone($name)];
        } catch (\Throwable $error) {
            // A timezone the CALLER named and PHP does not know is their mistake and
            // is reported as such. One we inherited from the site or the event is
            // not, so we fall through to the next candidate rather than refuse.
            if ($explicit && $name === trim((string) $raw)) {
                return nibwp_eventin_err(
                    'bad_timezone',
                    sprintf(
                        /* translators: %s: the timezone value received */
                        __('"%s" is not a timezone PHP recognises. Use an Olson name such as Europe/Rome, or a UTC offset such as +02:00.', 'nibwp'),
                        trim((string) $raw)
                    )
                );
            }
        }
    }

    return nibwp_eventin_err('bad_timezone', __('No usable timezone could be resolved.', 'nibwp'), 500);
}

/** Is this value a yes? */
function nibwp_eventin_truthy(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return (float) $value !== 0.0;
    }

    if (is_string($value)) {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    return false;
}

/**
 * Build the whole date meta set from a caller's payload, or refuse.
 *
 * All five keys or none. A writer that produces `etn_start_date` and leaves
 * `event_timezone` empty makes an event whose status flips with the server's own
 * timezone, and one that leaves `etn_end_date` empty makes an event whose end is
 * inferred differently by every reader (core/event/event-model.php:345-352). So
 * the end defaults to the start rather than to nothing, and the timezone always
 * resolves to something real.
 *
 * @return array<string, string>|WP_Error
 */
function nibwp_eventin_build_date_meta(array $data, ?int $event_id): array|WP_Error
{
    $has_start = array_key_exists('start_date', $data) && $data['start_date'] !== null && $data['start_date'] !== '';
    $has_end = array_key_exists('end_date', $data) && $data['end_date'] !== null && $data['end_date'] !== '';

    if (!$has_start && !$has_end && !array_key_exists('timezone', $data) && !array_key_exists('all_day', $data)) {
        return [];
    }

    $zone = nibwp_eventin_timezone($data['timezone'] ?? '', $event_id);

    if ($zone instanceof WP_Error) {
        return $zone;
    }

    $start = null;

    if ($has_start) {
        $start = nibwp_eventin_parse_datetime($data['start_date'], 'start_date', $zone['object']);

        if ($start instanceof WP_Error) {
            return $start;
        }
    } elseif ($event_id !== null && $event_id > 0) {
        // An update that changes only the timezone must not wipe the dates, so the
        // stored pair is read back and re-written alongside the new zone.
        $stored_date = get_post_meta($event_id, 'etn_start_date', true);
        $stored_time = get_post_meta($event_id, 'etn_start_time', true);

        if (is_string($stored_date) && $stored_date !== '') {
            $start = [
                'date' => $stored_date,
                'time' => is_string($stored_time) ? $stored_time : '',
                'date_only' => !is_string($stored_time) || '' === $stored_time,
            ];
        }
    }

    if ($start === null) {
        return nibwp_eventin_err('bad_request', __('start_date is required: Eventin cannot place an event without one.', 'nibwp'));
    }

    if ($has_end) {
        $end = nibwp_eventin_parse_datetime($data['end_date'], 'end_date', $zone['object']);

        if ($end instanceof WP_Error) {
            return $end;
        }
    } else {
        // An event with no end ends when it starts, which is the fallback the
        // plugin's own model already applies (core/event/event-model.php:348-351).
        $end = $start;
    }

    $all_day = array_key_exists('all_day', $data)
        ? nibwp_eventin_truthy($data['all_day'])
        : ($start['date_only'] && $end['date_only']);

    $start_time = $all_day ? '' : (string) $start['time'];
    $end_time = $all_day ? '' : (string) $end['time'];

    // Compared as strings because both sides are Y-m-d H:i, which sorts
    // lexicographically exactly as it sorts chronologically. An all-day event runs
    // to the end of its last day, which is why the blank end time means 23:59 here
    // and not midnight.
    $start_stamp = (string) $start['date'] . ' ' . ($start_time !== '' ? $start_time : '00:00');
    $end_stamp = (string) $end['date'] . ' ' . ($end_time !== '' ? $end_time : '23:59');

    if ($end_stamp < $start_stamp) {
        return nibwp_eventin_err(
            'bad_date_range',
            sprintf(
                /* translators: 1: end date and time, 2: start date and time */
                __('The end (%1$s) is before the start (%2$s). Refusing rather than silently swapping them.', 'nibwp'),
                $end_stamp,
                $start_stamp
            )
        );
    }

    return [
        'etn_start_date' => (string) $start['date'],
        'etn_end_date' => (string) $end['date'],
        'etn_start_time' => $start_time,
        'etn_end_time' => $end_time,
        'event_timezone' => (string) $zone['name'],
    ];
}

/** Today, in the site's own reckoning, in the one shape the queries can read. */
function nibwp_eventin_today(): string
{
    if (function_exists('current_time')) {
        $now = current_time(NIBWP_EVENTIN_DATE_FORMAT);

        if (is_string($now) && $now !== '') {
            return $now;
        }
    }

    return gmdate(NIBWP_EVENTIN_DATE_FORMAT);
}

/** The post statuses a listing may ask for. */
function nibwp_eventin_statuses(mixed $raw): array
{
    $allowed = ['publish', 'draft', 'pending', 'private', 'future', 'trash'];

    if (is_string($raw) && trim($raw) !== '') {
        $raw = array_map('trim', explode(',', $raw));
    }

    if (!is_array($raw) || $raw === []) {
        return ['publish', 'draft', 'pending', 'private', 'future'];
    }

    $out = [];

    foreach ($raw as $status) {
        if (is_string($status) && in_array($status, $allowed, true)) {
            $out[] = $status;
        }
    }

    return $out === [] ? ['publish'] : array_values(array_unique($out));
}

/** The post statuses `set_event_status` will write. */
function nibwp_eventin_writable_statuses(): array
{
    return ['publish', 'draft', 'pending', 'private', 'future'];
}

/**
 * Event meta whose VALUE may travel, and what shape to report it in.
 *
 * Everything else on the post is named and withheld. Eventin's own add-ons and
 * every CRM bridge in core/event/event-model.php:50-62 write to this same post,
 * so the allow-list is what keeps a webhook URL or an API handle from riding
 * along with an event read.
 *
 * @return array<string, string>
 */
function nibwp_eventin_public_event_meta(): array
{
    return [
        'etn_start_date' => 'string',
        'etn_end_date' => 'string',
        'etn_start_time' => 'string',
        'etn_end_time' => 'string',
        'event_timezone' => 'string',
        'etn_event_location' => 'string',
        'etn_event_location_type' => 'string',
        'etn_registration_deadline' => 'string',
        'etn_total_sold_tickets' => 'int',
        'etn_total_avaiilable_tickets' => 'int',
        'etn_ticket_availability' => 'string',
        'etn_enable_global_stock' => 'bool',
        'etn_global_stock' => 'int',
        'event_type' => 'string',
        'event_layout' => 'string',
        'recurring_enabled' => 'bool',
        'enable_seatmap' => 'bool',
        'etn_last_update_date' => 'string',
    ];
}

/**
 * Attendee meta whose VALUE may travel.
 *
 * `etn_info_edit_token` is absent on purpose and asserted absent by the check:
 * it authenticates an unauthenticated ticket-edit URL, so handing it out hands
 * out the ability to rewrite somebody else's booking.
 *
 * @return array<string, string>
 */
function nibwp_eventin_public_attendee_meta(): array
{
    return [
        'etn_name' => 'string',
        'etn_email' => 'string',
        'etn_phone' => 'string',
        'etn_event_id' => 'int',
        'eventin_order_id' => 'int',
        'etn_unique_ticket_id' => 'string',
        'ticket_name' => 'string',
        'ticket_slug' => 'string',
        'etn_attendeee_ticket_status' => 'string',
        'etn_ticket_price' => 'string',
        'etn_status' => 'string',
        'attendee_seat' => 'string',
    ];
}

/** Schedule meta whose VALUE may travel (core/schedule/schedule-model.php:30-35). */
function nibwp_eventin_public_schedule_meta(): array
{
    return [
        'etn_schedule_title' => 'string',
        'etn_schedule_date' => 'string',
        'etn_schedule_day' => 'string',
        'hide_date_on_event_page' => 'bool',
    ];
}

/** Speaker and organiser user meta whose VALUE may travel (core/speaker/user-model.php:22 names the prefix). */
function nibwp_eventin_public_person_meta(): array
{
    return [
        'etn_speaker_designation' => 'string',
        'etn_speaker_company_name' => 'string',
        'etn_speaker_company_url' => 'string',
        'etn_speaker_summery' => 'string',
        'etn_speaker_website_email' => 'string',
        'etn_speaker_image' => 'string',
    ];
}

/**
 * Settings fields whose VALUE may travel. Everything else in the flat
 * `etn_event_options` array is a name and whether it is set.
 *
 * @return array<string, string>
 */
function nibwp_eventin_public_settings(): array
{
    return [
        'attendee_registration' => 'string',
        'currency_position' => 'string',
        'decimal_separator' => 'string',
        'decimals' => 'int',
        'enable_tax' => 'string',
        'enable_purchase_email' => 'string',
        'disable_ticket_email' => 'string',
        'enable_attendee_bulk' => 'string',
        'enable_email_purchase_limit' => 'string',
        'email_purchase_max_limit' => 'int',
        'events_per_page' => 'int',
        'archive_event_sorting' => 'string',
        'archive_event_sorting_order' => 'string',
        'date_format' => 'string',
        'time_format' => 'string',
        'etn_primary_color' => 'string',
        'etn_secondary_color' => 'string',
        'etn_purchase_login_required' => 'string',
        'etn_show_expired_in_search' => 'string',
        'etn_settings_country_currency' => 'string',
        'paypal_status' => 'string',
        'paypal_sandbox' => 'string',
        'local_payment_status' => 'string',
        'etn_hide_seats_from_details' => 'string',
    ];
}

/**
 * Settings fields whose value must never travel, however it is asked for.
 *
 * A second layer rather than the only one: the allow-list above already governs
 * what leaves. A name landing in both lists is a mistake worth failing loudly
 * on, which the check asserts.
 *
 * @return array<int, string>
 */
function nibwp_eventin_secret_settings(): array
{
    return [
        'paypal_client_id',
        'paypal_client_secret',
        'stripe_live_secret_key',
        'stripe_live_publishable_key',
        'striple_publishable_key',
        'etn_stripe_webhook_secret',
        'etn_sells_engine_stripe',
        'zoom_api_key',
        'zoom_secret_key',
        'zoom_client_secret',
        'etn_zoom_api',
        'google_api_key',
        'google_token',
        'google_meet_client_id',
        'google_meet_client_secret_key',
        'google_meet_authorize_url',
        'etn_googlemap_api',
        'etn_meet_api',
        'etn_ai_api',
        'mail_mint_api',
        'mailpoet_api',
        'funnel_kit_api',
    ];
}

/**
 * Cast a meta value for reporting, or say it cannot be reported.
 *
 * A non-scalar returns null and the caller DROPS the key rather than casting it:
 * `(string) $array` is the word "Array", which tells the reader nothing true and
 * can hide a serialised payment blob behind five harmless characters.
 */
function nibwp_eventin_cast(mixed $value, string $as): mixed
{
    if (is_bool($value)) {
        return 'bool' === $as ? $value : ('int' === $as ? (int) $value : ($value ? '1' : ''));
    }

    if (!is_scalar($value)) {
        return null;
    }

    switch ($as) {
        case 'int':
            return (int) $value;
        case 'float':
            return (float) $value;
        case 'bool':
            return nibwp_eventin_truthy($value);
    }

    return (string) $value;
}

/**
 * Read meta through an allow-list, and name what was held back.
 *
 * The withheld list matters as much as the allowed one: an owner asking why an
 * event behaves oddly needs to know an add-on has attached fourteen keys to it,
 * without any of those fourteen values crossing the wire.
 *
 * @param array<string, string> $allow
 * @return array{values:array<string, mixed>, withheld:array<int, array<string, mixed>>}
 */
function nibwp_eventin_read_meta(int $post_id, array $allow): array
{
    $values = [];

    foreach ($allow as $key => $as) {
        $raw = get_post_meta($post_id, $key, true);

        if ($raw === '' || $raw === false || $raw === null) {
            continue;
        }

        $cast = nibwp_eventin_cast($raw, $as);

        if ($cast === null) {
            continue;
        }

        $values[$key] = $cast;
    }

    $withheld = [];
    $all = get_post_meta($post_id);

    if (is_array($all)) {
        foreach (array_keys($all) as $key) {
            $key = (string) $key;

            if (isset($allow[$key])) {
                continue;
            }

            $withheld[] = ['key' => $key, 'value_returned' => false];
        }
    }

    return ['values' => $values, 'withheld' => $withheld];
}

/** The same, for a user's meta. */
function nibwp_eventin_read_user_meta(int $user_id, array $allow): array
{
    $values = [];

    foreach ($allow as $key => $as) {
        $raw = get_user_meta($user_id, $key, true);

        if ($raw === '' || $raw === false || $raw === null) {
            continue;
        }

        $cast = nibwp_eventin_cast($raw, $as);

        if ($cast === null) {
            continue;
        }

        $values[$key] = $cast;
    }

    return $values;
}

/** Content goes out the way the block editor sends it in: slashed, unkessed by us. */
function nibwp_eventin_content(mixed $raw): string
{
    $text = is_scalar($raw) ? (string) $raw : '';

    if (function_exists('nibwp_wp_prepare_post_content')) {
        return (string) nibwp_wp_prepare_post_content($text);
    }

    // wp_insert_post() unslashes, and core runs content_save_pre — which includes
    // kses for anybody without unfiltered_html. Adding our own would double-escape.
    return wp_slash($text);
}

/** How many rows were left out, said plainly rather than hidden. */
function nibwp_eventin_skipped_note(int $skipped): string
{
    if ($skipped < 1) {
        return '';
    }

    return sprintf(
        /* translators: %d: number of records omitted */
        _n(
            '%d record was left out because this account cannot edit it, so this list is not complete.',
            '%d records were left out because this account cannot edit them, so this list is not complete.',
            $skipped,
            'nibwp'
        ),
        $skipped
    );
}

/** The slim shape a listing uses for one event. */
function nibwp_eventin_event_row(WP_Post $event): array
{
    $start_time = get_post_meta($event->ID, 'etn_start_time', true);

    return [
        'id' => (int) $event->ID,
        'title' => (string) $event->post_title,
        'status' => (string) $event->post_status,
        'start_date' => (string) get_post_meta($event->ID, 'etn_start_date', true),
        'end_date' => (string) get_post_meta($event->ID, 'etn_end_date', true),
        'start_time' => is_scalar($start_time) ? (string) $start_time : '',
        'end_time' => (string) get_post_meta($event->ID, 'etn_end_time', true),
        'timezone' => (string) get_post_meta($event->ID, 'event_timezone', true),
        // Eventin has no all-day flag: a blank start time IS the all-day case.
        'all_day' => '' === (is_scalar($start_time) ? (string) $start_time : ''),
        'permalink' => (string) get_permalink($event->ID),
    ];
}

/**
 * Normalise one entry of `etn_ticket_variations`.
 *
 * The variation array is the one place Eventin keeps price and stock
 * (core/event/event-model.php:44, read at :169, :250, :409). Its keys are
 * misspelled in the plugin — `etn_avaiilable_tickets` — and renaming them in a
 * write would mean storing a key no Eventin reader looks at, so the stored names
 * are kept and only the output is tidied.
 */
function nibwp_eventin_ticket_row(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    $map = [
        'etn_ticket_name' => ['name', 'string'],
        'etn_ticket_slug' => ['slug', 'string'],
        'etn_ticket_price' => ['price', 'float'],
        'etn_max_ticket' => ['stock', 'int'],
        'etn_min_ticket' => ['minimum_per_order', 'int'],
        'etn_sold_tickets' => ['sold', 'int'],
        'etn_avaiilable_tickets' => ['available', 'int'],
    ];

    $row = [];

    foreach ($map as $key => $pair) {
        if (!array_key_exists($key, $raw)) {
            continue;
        }

        $cast = nibwp_eventin_cast($raw[$key], $pair[1]);

        if ($cast === null) {
            continue;
        }

        $row[$pair[0]] = $cast;
    }

    return $row;
}

/** Every ticket type on an event, already normalised. */
function nibwp_eventin_tickets_of(int $event_id): array
{
    $raw = get_post_meta($event_id, 'etn_ticket_variations', true);

    if (is_string($raw) && $raw !== '') {
        // Some import paths store the variations as JSON rather than as an array.
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [];
    }

    if (!is_array($raw)) {
        return [];
    }

    $rows = [];

    foreach ($raw as $variation) {
        $row = nibwp_eventin_ticket_row($variation);

        if ($row !== []) {
            $rows[] = $row;
        }
    }

    return $rows;
}

/** Run a post query without letting a broken plugin filter take the request down. */
function nibwp_eventin_query_posts(array $args): array
{
    try {
        $posts = get_posts($args);
    } catch (\Throwable $error) {
        return [];
    }

    if (!is_array($posts)) {
        return [];
    }

    return array_values(array_filter($posts, static fn($post) => $post instanceof WP_Post));
}

/** Does a table exist? Asked before any read of a custom table. */
function nibwp_eventin_table_exists(string $table): bool
{
    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
        return false;
    }

    try {
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    } catch (\Throwable $error) {
        return false;
    }

    return is_string($found) && $found !== '';
}

/* ────────────────────────────── the action list ──────────────────────────── */

/**
 * Every action this hub answers to.
 *
 * The names are deliberately the SAME names the Events Calendar integration uses
 * for the same ideas — `list_events`, `get_event`, `create_event`, `update_event`,
 * `set_event_status`, `trash_event`, `delete_event_permanently`,
 * `upcoming_events`, `list_organizers`, `get_organizer`, `organizer_events`,
 * `audit_calendar` — so an agent that has learned one events plugin on this site
 * does not have to learn a second vocabulary to use the other. Where Eventin has
 * something that plugin does not (speakers as users, schedules, ticket types,
 * attendees, revenue) the new names follow the same shape: `list_*`, `get_*`,
 * `*_events`.
 */
function nibwp_eventin_actions(): array
{
    return [
        'list_events',
        'get_event',
        'create_event',
        'update_event',
        'set_event_status',
        'trash_event',
        'delete_event_permanently',
        'upcoming_events',
        'list_speakers',
        'get_speaker',
        'speaker_events',
        'list_organizers',
        'get_organizer',
        'organizer_events',
        'list_schedules',
        'get_schedule',
        'event_schedule',
        'list_ticket_types',
        'ticket_availability',
        'list_attendees',
        'get_attendee',
        'count_attendees',
        'list_checkins',
        'event_revenue',
        'event_settings',
        'audit_calendar',
    ];
}

/**
 * The names this hub shares with the Events Calendar integration.
 *
 * Asserted present by the check, so a later rename here has to argue with a test
 * rather than quietly split the vocabulary in two.
 */
function nibwp_eventin_aligned_actions(): array
{
    return [
        'list_events',
        'get_event',
        'create_event',
        'update_event',
        'set_event_status',
        'trash_event',
        'delete_event_permanently',
        'upcoming_events',
        'list_organizers',
        'get_organizer',
        'organizer_events',
        'audit_calendar',
    ];
}

/* ──────────────────────────────── events ─────────────────────────────────── */

/**
 * A date range for a listing, either end optional.
 *
 * @return array{from:string,to:string}|WP_Error
 */
function nibwp_eventin_range(array $input): array|WP_Error
{
    $zone = nibwp_eventin_timezone($input['timezone'] ?? '', null);

    if ($zone instanceof WP_Error) {
        return $zone;
    }

    $from = '';
    $to = '';

    if (isset($input['from_date']) && '' !== $input['from_date']) {
        $parsed = nibwp_eventin_parse_datetime($input['from_date'], 'from_date', $zone['object']);

        if ($parsed instanceof WP_Error) {
            return $parsed;
        }

        $from = (string) $parsed['date'];
    }

    if (isset($input['to_date']) && '' !== $input['to_date']) {
        $parsed = nibwp_eventin_parse_datetime($input['to_date'], 'to_date', $zone['object']);

        if ($parsed instanceof WP_Error) {
            return $parsed;
        }

        $to = (string) $parsed['date'];
    }

    if ($from !== '' && $to !== '' && $to < $from) {
        return nibwp_eventin_err(
            'bad_date_range',
            sprintf(
                /* translators: 1: to_date, 2: from_date */
                __('to_date (%1$s) is before from_date (%2$s).', 'nibwp'),
                $to,
                $from
            )
        );
    }

    return ['from' => $from, 'to' => $to];
}

/**
 * The meta_query for a range.
 *
 * An event OVERLAPS the window rather than starting inside it: an eight-day
 * conference that began before `from_date` is still on during it, and a listing
 * that dropped it would be wrong in the way that matters. `'type' => 'DATE'` on
 * every clause because the values are Y-m-d and Eventin's own queries cast the
 * same way (utils/helper.php:2965-2971).
 */
function nibwp_eventin_range_meta_query(array $range): array
{
    $clauses = [];

    if ('' !== $range['to']) {
        $clauses[] = [
            'key' => 'etn_start_date',
            'value' => $range['to'],
            'compare' => '<=',
            'type' => 'DATE',
        ];
    }

    if ('' !== $range['from']) {
        $clauses[] = [
            'key' => 'etn_end_date',
            'value' => $range['from'],
            'compare' => '>=',
            'type' => 'DATE',
        ];
    }

    if ($clauses === []) {
        return [];
    }

    $clauses['relation'] = 'AND';

    return $clauses;
}

/** Events in a date range, with a status filter. */
function nibwp_eventin_list_events(array $input): array|WP_Error
{
    $denied = nibwp_eventin_require(__('Listing events', 'nibwp'), NIBWP_EVENTIN_CAP_EVENT);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $range = nibwp_eventin_range($input);

    if ($range instanceof WP_Error) {
        return $range;
    }

    $per_page = nibwp_eventin_clamp($input['per_page'] ?? null, NIBWP_EVENTIN_DEFAULT_ROWS, NIBWP_EVENTIN_MAX_ROWS);
    $page = nibwp_eventin_clamp($input['page'] ?? null, 1, 1000);
    $meta_query = nibwp_eventin_range_meta_query($range);

    $args = [
        'post_type' => nibwp_eventin_type('event'),
        'post_status' => nibwp_eventin_statuses($input['status'] ?? null),
        'posts_per_page' => $per_page,
        'paged' => $page,
        'orderby' => 'meta_value',
        'meta_key' => 'etn_start_date',
        'order' => 'ASC',
        'suppress_filters' => false,
    ];

    if ($meta_query !== []) {
        $args['meta_query'] = $meta_query;
    }

    if (isset($input['search']) && is_string($input['search']) && '' !== trim($input['search'])) {
        $args['s'] = sanitize_text_field($input['search']);
    }

    $rows = [];
    $skipped = 0;

    foreach (nibwp_eventin_query_posts($args) as $event) {
        if (!nibwp_eventin_can_edit((int) $event->ID)) {
            $skipped++;
            continue;
        }

        $rows[] = nibwp_eventin_event_row($event);
    }

    return [
        'events' => $rows,
        'total' => count($rows),
        'per_page' => $per_page,
        'page' => $page,
        'from_date' => $range['from'],
        'to_date' => $range['to'],
        'skipped' => $skipped,
        'skipped_note' => nibwp_eventin_skipped_note($skipped),
    ];
}

/** The next events from today onwards. */
function nibwp_eventin_upcoming(array $input): array|WP_Error
{
    $denied = nibwp_eventin_require(__('Listing upcoming events', 'nibwp'), NIBWP_EVENTIN_CAP_EVENT);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $count = nibwp_eventin_clamp($input['count'] ?? ($input['per_page'] ?? null), NIBWP_EVENTIN_DEFAULT_ROWS, NIBWP_EVENTIN_MAX_ROWS);
    $today = nibwp_eventin_today();

    $args = [
        'post_type' => nibwp_eventin_type('event'),
        'post_status' => ['publish'],
        'posts_per_page' => $count,
        'orderby' => 'meta_value',
        'meta_key' => 'etn_start_date',
        'order' => 'ASC',
        'suppress_filters' => false,
        'meta_query' => [
            [
                'key' => 'etn_end_date',
                'value' => $today,
                'compare' => '>=',
                'type' => 'DATE',
            ],
        ],
    ];

    $rows = [];
    $skipped = 0;

    foreach (nibwp_eventin_query_posts($args) as $event) {
        if (!nibwp_eventin_can_edit((int) $event->ID)) {
            $skipped++;
            continue;
        }

        $rows[] = nibwp_eventin_event_row($event);
    }

    return [
        'events' => $rows,
        'total' => count($rows),
        'per_page' => $count,
        'from_date' => $today,
        'skipped' => $skipped,
        'skipped_note' => nibwp_eventin_skipped_note($skipped),
    ];
}

/** One event in full. */
function nibwp_eventin_get_event(array $input): array|WP_Error
{
    $resolved = nibwp_eventin_resolve((int) ($input['event_id'] ?? 0), 'event');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $event = $resolved['post'];
    $id = (int) $event->ID;

    $denied = nibwp_eventin_require_post_read($id, __('Reading an event', 'nibwp'), NIBWP_EVENTIN_CAP_EVENT);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $meta = nibwp_eventin_read_meta($id, nibwp_eventin_public_event_meta());
    $tickets = nibwp_eventin_tickets_of($id);

    $missing = [];

    foreach (nibwp_eventin_required_date_meta() as $key) {
        $value = get_post_meta($id, $key, true);

        // A blank TIME is the all-day case and not a fault; a blank DATE or a
        // blank timezone is what makes an event invisible.
        if (in_array($key, ['etn_start_time', 'etn_end_time'], true)) {
            continue;
        }

        if (!is_scalar($value) || '' === (string) $value) {
            $missing[] = $key;
        }
    }

    return [
        'event' => array_merge(
            nibwp_eventin_event_row($event),
            [
                'content' => (string) $event->post_content,
                'excerpt' => (string) $event->post_excerpt,
                'meta' => $meta['values'],
                'ticket_types' => $tickets,
                'ticket_type_count' => count($tickets),
                'speaker_ids' => nibwp_eventin_linked_ids($id, 'speaker'),
                'organizer_ids' => nibwp_eventin_linked_ids($id, 'organizer'),
                'missing_date_meta' => $missing,
            ]
        ),
        'withheld_meta' => $meta['withheld'],
    ];
}

/**
 * The speaker or organiser user ids an event points at.
 *
 * Stored as `etn_event_speaker` / `etn_event_organizer`, which may hold an array,
 * a single id or a comma list depending on how the event was created
 * (core/event/event-model.php:35-36 declares them, EventImporter.php:190 writes
 * the list form).
 */
function nibwp_eventin_linked_ids(int $event_id, string $kind): array
{
    $key = 'organizer' === $kind ? 'etn_event_organizer' : 'etn_event_speaker';
    $raw = get_post_meta($event_id, $key, true);

    if (is_string($raw)) {
        $raw = '' === trim($raw) ? [] : explode(',', $raw);
    }

    if (!is_array($raw)) {
        return [];
    }

    $ids = [];

    foreach ($raw as $value) {
        if (!is_scalar($value)) {
            continue;
        }

        $id = (int) $value;

        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
}

/**
 * Turn a caller's event payload into post arguments plus date meta.
 *
 * @return array{post:array<string, mixed>, meta:array<string, string>}|WP_Error
 */
function nibwp_eventin_event_args(array $data, ?int $event_id): array|WP_Error
{
    $is_create = null === $event_id;
    $post = [];

    if (array_key_exists('title', $data)) {
        $title = is_scalar($data['title']) ? sanitize_text_field((string) $data['title']) : '';

        if ('' === $title && $is_create) {
            return nibwp_eventin_err('bad_request', __('event_data.title is required to create an event.', 'nibwp'));
        }

        if ('' !== $title) {
            $post['post_title'] = $title;
        }
    } elseif ($is_create) {
        return nibwp_eventin_err('bad_request', __('event_data.title is required to create an event.', 'nibwp'));
    }

    if (array_key_exists('content', $data)) {
        $post['post_content'] = nibwp_eventin_content($data['content']);
    }

    if (array_key_exists('excerpt', $data)) {
        $post['post_excerpt'] = is_scalar($data['excerpt']) ? sanitize_text_field((string) $data['excerpt']) : '';
    }

    if (array_key_exists('status', $data)) {
        $status = is_scalar($data['status']) ? (string) $data['status'] : '';

        if (!in_array($status, nibwp_eventin_writable_statuses(), true)) {
            return nibwp_eventin_err(
                'bad_status',
                sprintf(
                    /* translators: 1: the status requested, 2: comma separated list of valid statuses */
                    __('"%1$s" is not a status this integration will set. Allowed: %2$s.', 'nibwp'),
                    $status,
                    implode(', ', nibwp_eventin_writable_statuses())
                )
            );
        }

        $post['post_status'] = $status;
    } elseif ($is_create) {
        // A new event starts as a draft. Publishing something to the public
        // calendar is a decision, not a side effect of being created.
        $post['post_status'] = 'draft';
    }

    $dates = nibwp_eventin_build_date_meta($data, $event_id);

    if ($dates instanceof WP_Error) {
        return $dates;
    }

    if ($is_create && $dates === []) {
        return nibwp_eventin_err('bad_request', __('event_data.start_date is required to create an event.', 'nibwp'));
    }

    $meta = $dates;

    foreach (['location' => 'etn_event_location', 'location_type' => 'etn_event_location_type', 'event_type' => 'event_type'] as $from => $to) {
        if (array_key_exists($from, $data) && is_scalar($data[$from])) {
            $meta[$to] = sanitize_text_field((string) $data[$from]);
        }
    }

    if (array_key_exists('registration_deadline', $data) && is_scalar($data['registration_deadline'])) {
        $zone = nibwp_eventin_timezone($data['timezone'] ?? '', $event_id);

        if ($zone instanceof WP_Error) {
            return $zone;
        }

        $parsed = nibwp_eventin_parse_datetime($data['registration_deadline'], 'registration_deadline', $zone['object']);

        if ($parsed instanceof WP_Error) {
            return $parsed;
        }

        $meta['etn_registration_deadline'] = (string) $parsed['date'];
    }

    return ['post' => $post, 'meta' => $meta];
}

/** Create an event, as a draft unless told otherwise. */
function nibwp_eventin_create_event(array $input): array|WP_Error
{
    $denied = nibwp_eventin_require(__('Creating an event', 'nibwp'), NIBWP_EVENTIN_CAP_EVENT);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $data = is_array($input['event_data'] ?? null) ? $input['event_data'] : [];

    if ($data === []) {
        return nibwp_eventin_err('bad_request', __('event_data is required, with at least a title and a start_date.', 'nibwp'));
    }

    $args = nibwp_eventin_event_args($data, null);

    if ($args instanceof WP_Error) {
        return $args;
    }

    $postarr = $args['post'];
    $postarr['post_type'] = nibwp_eventin_type('event');
    $postarr['meta_input'] = $args['meta'];

    $id = wp_insert_post($postarr, true);

    if ($id instanceof WP_Error) {
        return nibwp_eventin_err('create_failed', $id->get_error_message(), 500);
    }

    $id = (int) $id;

    if ($id <= 0) {
        return nibwp_eventin_err('create_failed', __('WordPress returned no id for the new event.', 'nibwp'), 500);
    }

    return [
        'event_id' => $id,
        'written_date_meta' => $args['meta'],
        'message' => sprintf(
            /* translators: 1: post ID, 2: post status */
            __('Event %1$d created with status "%2$s".', 'nibwp'),
            $id,
            (string) ($postarr['post_status'] ?? 'draft')
        ),
    ];
}

/** Update an event. */
function nibwp_eventin_update_event(array $input): array|WP_Error
{
    $resolved = nibwp_eventin_resolve((int) ($input['event_id'] ?? 0), 'event');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $id = (int) $resolved['post']->ID;
    $denied = nibwp_eventin_require_event_write($id, __('Updating an event', 'nibwp'), false);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $data = is_array($input['event_data'] ?? null) ? $input['event_data'] : [];

    if ($data === []) {
        return nibwp_eventin_err('bad_request', __('event_data is required and must name at least one field to change.', 'nibwp'));
    }

    $args = nibwp_eventin_event_args($data, $id);

    if ($args instanceof WP_Error) {
        return $args;
    }

    if ($args['post'] !== []) {
        $postarr = $args['post'];
        $postarr['ID'] = $id;
        $updated = wp_update_post($postarr, true);

        if ($updated instanceof WP_Error) {
            return nibwp_eventin_err('update_failed', $updated->get_error_message(), 500);
        }
    }

    foreach ($args['meta'] as $key => $value) {
        update_post_meta($id, $key, $value);
    }

    return [
        'event_id' => $id,
        'written_date_meta' => $args['meta'],
        'message' => sprintf(
            /* translators: %d: post ID */
            __('Event %d updated.', 'nibwp'),
            $id
        ),
    ];
}

/** The date meta an event is missing, which is what keeps it off the calendar. */
function nibwp_eventin_missing_date_meta(int $id): array
{
    $missing = [];

    foreach (['etn_start_date', 'etn_end_date', 'event_timezone'] as $key) {
        $value = get_post_meta($id, $key, true);

        if (!is_scalar($value) || '' === (string) $value) {
            $missing[] = $key;
        }
    }

    return $missing;
}

/** Change an event's status, refusing to publish one that would be invisible. */
function nibwp_eventin_set_event_status(array $input): array|WP_Error
{
    $resolved = nibwp_eventin_resolve((int) ($input['event_id'] ?? 0), 'event');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $id = (int) $resolved['post']->ID;

    $denied = nibwp_eventin_require_event_write($id, __('Changing an event status', 'nibwp'), false);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $status = is_scalar($input['status'] ?? null) ? (string) $input['status'] : '';

    if (!in_array($status, nibwp_eventin_writable_statuses(), true)) {
        return nibwp_eventin_err(
            'bad_status',
            sprintf(
                /* translators: 1: the status requested, 2: comma separated list of valid statuses */
                __('"%1$s" is not a status this integration will set. Allowed: %2$s.', 'nibwp'),
                $status,
                implode(', ', nibwp_eventin_writable_statuses())
            )
        );
    }

    if ('publish' === $status) {
        $missing = nibwp_eventin_missing_date_meta($id);

        if ($missing !== []) {
            return nibwp_eventin_err(
                'incomplete_dates',
                sprintf(
                    /* translators: 1: post ID, 2: comma separated list of meta keys */
                    __('Event %1$d cannot be published: it is missing %2$s. Published without them it would exist and never appear on the calendar. Set them with update_event first.', 'nibwp'),
                    $id,
                    implode(', ', $missing)
                ),
                409
            );
        }
    }

    $updated = wp_update_post(['ID' => $id, 'post_status' => $status], true);

    if ($updated instanceof WP_Error) {
        return nibwp_eventin_err('update_failed', $updated->get_error_message(), 500);
    }

    return [
        'event_id' => $id,
        'status' => $status,
        'message' => sprintf(
            /* translators: 1: post ID, 2: post status */
            __('Event %1$d is now "%2$s".', 'nibwp'),
            $id,
            $status
        ),
    ];
}

/** Trash an event. Reversible, and the one callers should reach for. */
function nibwp_eventin_trash_event(array $input): array|WP_Error
{
    $resolved = nibwp_eventin_resolve((int) ($input['event_id'] ?? 0), 'event');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $id = (int) $resolved['post']->ID;

    $denied = nibwp_eventin_require_event_write($id, __('Trashing an event', 'nibwp'), true);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $trashed = wp_trash_post($id);

    if (!$trashed) {
        return nibwp_eventin_err('trash_failed', __('WordPress refused to trash the event.', 'nibwp'), 500);
    }

    return [
        'event_id' => $id,
        'message' => sprintf(
            /* translators: %d: post ID */
            __('Event %d moved to trash. Restore it from wp-admin if this was wrong.', 'nibwp'),
            $id
        ),
    ];
}

/**
 * Delete an event and everything hanging off it. No trash, no undo.
 *
 * Eventin attaches attendees to an event by meta (`etn_event_id`) rather than by
 * post parent, so WordPress does not cascade — the attendee posts survive as
 * orphans pointing at an id that no longer exists, which is worse than either
 * outcome. So the attendees are counted BEFORE the delete and reported, and the
 * orphan ids are named, so the owner knows exactly what they are left holding.
 * The attendee records are not deleted here: destroying people's booking history
 * is not a side effect this integration will take on a caller's behalf.
 */
function nibwp_eventin_delete_event_permanently(array $input): array|WP_Error
{
    $resolved = nibwp_eventin_resolve((int) ($input['event_id'] ?? 0), 'event');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $id = (int) $resolved['post']->ID;

    $denied = nibwp_eventin_require_event_write($id, __('Permanently deleting an event', 'nibwp'), true);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    if (true !== ($input['confirm'] ?? null)) {
        return nibwp_eventin_err(
            'confirm_required',
            sprintf(
                /* translators: %d: post ID */
                __('Permanently deleting event %d is irreversible and leaves its attendee records pointing at an event that no longer exists. Send confirm: true once the owner has agreed.', 'nibwp'),
                $id
            ),
            409
        );
    }

    $attendees = nibwp_eventin_attendee_ids_for_event($id, NIBWP_EVENTIN_MAX_ROWS);
    $title = (string) $resolved['post']->post_title;

    $deleted = wp_delete_post($id, true);

    if (!$deleted) {
        return nibwp_eventin_err('delete_failed', __('WordPress refused to delete the event.', 'nibwp'), 500);
    }

    return [
        'event_id' => $id,
        'title' => $title,
        'orphaned_attendee_count' => count($attendees),
        'orphaned_attendee_ids' => $attendees,
        'message' => sprintf(
            /* translators: 1: post ID, 2: number of attendee records left behind */
            __('Event %1$d deleted permanently. %2$d attendee records still name it and are now orphans; delete them from the Attendees screen if that is what the owner wants.', 'nibwp'),
            $id,
            count($attendees)
        ),
    ];
}

/* ───────────────────── speakers and organisers (users) ───────────────────── */

/** The slim shape a listing uses for a speaker or organiser. */
function nibwp_eventin_person_row(WP_User $user): array
{
    return [
        'id' => (int) $user->ID,
        'name' => (string) ($user->display_name ?? ''),
        'email' => (string) ($user->user_email ?? ''),
        'roles' => is_array($user->roles ?? null) ? array_values(array_map('strval', $user->roles)) : [],
        'meta' => nibwp_eventin_read_user_meta((int) $user->ID, nibwp_eventin_public_person_meta()),
    ];
}

/** Speakers or organisers, bounded. */
function nibwp_eventin_list_people(string $kind, array $input): array|WP_Error
{
    $denied = nibwp_eventin_require(
        'organizer' === $kind ? __('Listing organisers', 'nibwp') : __('Listing speakers', 'nibwp'),
        NIBWP_EVENTIN_CAP_ORGANIZER
    );

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $per_page = nibwp_eventin_clamp($input['per_page'] ?? null, NIBWP_EVENTIN_DEFAULT_ROWS, NIBWP_EVENTIN_MAX_ROWS);
    $page = nibwp_eventin_clamp($input['page'] ?? null, 1, 1000);

    $args = [
        'role' => nibwp_eventin_role($kind),
        'number' => $per_page,
        'paged' => $page,
        'orderby' => 'display_name',
        'order' => 'ASC',
    ];

    if (isset($input['search']) && is_string($input['search']) && '' !== trim($input['search'])) {
        $args['search'] = '*' . sanitize_text_field($input['search']) . '*';
    }

    try {
        $users = get_users($args);
    } catch (\Throwable $error) {
        $users = [];
    }

    $rows = [];

    foreach (is_array($users) ? $users : [] as $user) {
        if ($user instanceof WP_User) {
            $rows[] = nibwp_eventin_person_row($user);
        }
    }

    return [
        'organizer' === $kind ? 'organizers' : 'speakers' => $rows,
        'total' => count($rows),
        'per_page' => $per_page,
        'page' => $page,
    ];
}

/** One speaker or organiser. */
function nibwp_eventin_get_person(string $kind, array $input): array|WP_Error
{
    $key = 'organizer' === $kind ? 'organizer_id' : 'speaker_id';
    $resolved = nibwp_eventin_resolve_person((int) ($input[$key] ?? 0), $kind);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $denied = nibwp_eventin_require(
        'organizer' === $kind ? __('Reading an organiser', 'nibwp') : __('Reading a speaker', 'nibwp'),
        NIBWP_EVENTIN_CAP_ORGANIZER
    );

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    return [
        'organizer' === $kind ? 'organizer' : 'speaker' => nibwp_eventin_person_row($resolved['user']),
    ];
}

/** Which events name this speaker or organiser. */
function nibwp_eventin_person_events(string $kind, array $input): array|WP_Error
{
    $key = 'organizer' === $kind ? 'organizer_id' : 'speaker_id';
    $resolved = nibwp_eventin_resolve_person((int) ($input[$key] ?? 0), $kind);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    // Both doors, and the refusal names whichever one is shut rather than always
    // blaming the second: a caller sent to ask for the wrong capability is no
    // better off than one told nothing.
    foreach ([NIBWP_EVENTIN_CAP_ORGANIZER, NIBWP_EVENTIN_CAP_EVENT] as $needed) {
        $denied = nibwp_eventin_require(__('Listing a person\'s events', 'nibwp'), $needed);

        if ($denied instanceof WP_Error) {
            return $denied;
        }
    }

    $id = (int) $resolved['user']->ID;
    $meta_key = 'organizer' === $kind ? 'etn_event_organizer' : 'etn_event_speaker';
    $per_page = nibwp_eventin_clamp($input['per_page'] ?? null, NIBWP_EVENTIN_DEFAULT_ROWS, NIBWP_EVENTIN_MAX_ROWS);

    $args = [
        'post_type' => nibwp_eventin_type('event'),
        'post_status' => nibwp_eventin_statuses($input['status'] ?? null),
        'posts_per_page' => $per_page,
        'suppress_filters' => false,
        'meta_query' => [
            [
                'key' => $meta_key,
                // LIKE because the meta can be a serialised array, a single id or a
                // comma list; the exact ids are re-checked in PHP below so the loose
                // match cannot widen the answer.
                'value' => (string) $id,
                'compare' => 'LIKE',
            ],
        ],
    ];

    $rows = [];
    $skipped = 0;

    foreach (nibwp_eventin_query_posts($args) as $event) {
        $event_id = (int) $event->ID;

        if (!in_array($id, nibwp_eventin_linked_ids($event_id, $kind), true)) {
            continue;
        }

        if (!nibwp_eventin_can_edit($event_id)) {
            $skipped++;
            continue;
        }

        $rows[] = nibwp_eventin_event_row($event);
    }

    return [
        'events' => $rows,
        'total' => count($rows),
        'per_page' => $per_page,
        $key => $id,
        'skipped' => $skipped,
        'skipped_note' => nibwp_eventin_skipped_note($skipped),
    ];
}

/* ─────────────────────────── schedules and agenda ────────────────────────── */

/** The slim shape a listing uses for one schedule. */
function nibwp_eventin_schedule_row(WP_Post $schedule): array
{
    return [
        'id' => (int) $schedule->ID,
        'title' => (string) $schedule->post_title,
        'status' => (string) $schedule->post_status,
        'date' => (string) get_post_meta($schedule->ID, 'etn_schedule_date', true),
        'day' => (string) get_post_meta($schedule->ID, 'etn_schedule_day', true),
    ];
}

/**
 * Agenda slots, normalised.
 *
 * `etn_schedule_topics` is a nested array of slots; none of it is scalar, so it
 * is rebuilt field by field rather than cast. Keys read off
 * core/schedule/ScheduleExporter.php:69 and the `etn_shedule_*` family that the
 * templates use (note the plugin's own spelling).
 */
function nibwp_eventin_schedule_slots(int $schedule_id): array
{
    $raw = get_post_meta($schedule_id, 'etn_schedule_topics', true);

    if (is_string($raw) && '' !== $raw) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [];
    }

    if (!is_array($raw)) {
        return [];
    }

    $map = [
        'etn_schedule_topic' => ['topic', 'string'],
        'etn_shedule_objective' => ['objective', 'string'],
        'etn_shedule_room' => ['room', 'string'],
        'etn_schedule_start_time' => ['start_time', 'string'],
        'etn_schedule_end_time' => ['end_time', 'string'],
        'etn_shedule_start_time' => ['start_time', 'string'],
        'etn_shedule_end_time' => ['end_time', 'string'],
    ];

    $slots = [];

    foreach ($raw as $slot) {
        if (!is_array($slot)) {
            continue;
        }

        $row = [];

        foreach ($map as $key => $pair) {
            if (!array_key_exists($key, $slot)) {
                continue;
            }

            $cast = nibwp_eventin_cast($slot[$key], $pair[1]);

            if ($cast === null || '' === $cast) {
                continue;
            }

            $row[$pair[0]] = $cast;
        }

        $speakers = $slot['etn_schedule_speaker'] ?? null;

        if (is_array($speakers)) {
            $row['speaker_ids'] = array_values(array_filter(array_map('intval', array_filter($speakers, 'is_scalar'))));
        } elseif (is_scalar($speakers) && '' !== (string) $speakers) {
            $row['speaker_ids'] = [(int) $speakers];
        }

        if ($row !== []) {
            $slots[] = $row;
        }
    }

    return $slots;
}

/** Schedules on the site, bounded. */
function nibwp_eventin_list_schedules(array $input): array|WP_Error
{
    $denied = nibwp_eventin_require(__('Listing schedules', 'nibwp'), NIBWP_EVENTIN_CAP_SCHEDULE);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $per_page = nibwp_eventin_clamp($input['per_page'] ?? null, NIBWP_EVENTIN_DEFAULT_ROWS, NIBWP_EVENTIN_MAX_ROWS);
    $page = nibwp_eventin_clamp($input['page'] ?? null, 1, 1000);

    $args = [
        'post_type' => nibwp_eventin_type('schedule'),
        'post_status' => nibwp_eventin_statuses($input['status'] ?? null),
        'posts_per_page' => $per_page,
        'paged' => $page,
        'suppress_filters' => false,
    ];

    $rows = [];
    $skipped = 0;

    foreach (nibwp_eventin_query_posts($args) as $schedule) {
        if (!nibwp_eventin_can_edit((int) $schedule->ID)) {
            $skipped++;
            continue;
        }

        $rows[] = nibwp_eventin_schedule_row($schedule);
    }

    return [
        'schedules' => $rows,
        'total' => count($rows),
        'per_page' => $per_page,
        'page' => $page,
        'skipped' => $skipped,
        'skipped_note' => nibwp_eventin_skipped_note($skipped),
    ];
}

/** One schedule, with its slots. */
function nibwp_eventin_get_schedule(array $input): array|WP_Error
{
    $resolved = nibwp_eventin_resolve((int) ($input['schedule_id'] ?? 0), 'schedule');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $id = (int) $resolved['post']->ID;

    $denied = nibwp_eventin_require_post_read($id, __('Reading a schedule', 'nibwp'), NIBWP_EVENTIN_CAP_SCHEDULE);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $meta = nibwp_eventin_read_meta($id, nibwp_eventin_public_schedule_meta());
    $slots = nibwp_eventin_schedule_slots($id);

    return [
        'schedule' => array_merge(
            nibwp_eventin_schedule_row($resolved['post']),
            ['meta' => $meta['values'], 'slots' => $slots, 'slot_count' => count($slots)]
        ),
        'withheld_meta' => $meta['withheld'],
    ];
}

/**
 * The agenda for one event.
 *
 * An event names its schedules in `etn_event_schedule` (core/event/event-model.php:66),
 * which holds schedule post ids. Reading it that way rather than querying the
 * schedule post type means an agenda slot that belongs to no event does not
 * appear here, which is what the caller asked for.
 */
function nibwp_eventin_event_schedule(array $input): array|WP_Error
{
    $resolved = nibwp_eventin_resolve((int) ($input['event_id'] ?? 0), 'event');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $id = (int) $resolved['post']->ID;

    $denied = nibwp_eventin_require_post_read($id, __('Reading an event agenda', 'nibwp'), NIBWP_EVENTIN_CAP_SCHEDULE);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $raw = get_post_meta($id, 'etn_event_schedule', true);

    if (is_string($raw)) {
        $raw = '' === trim($raw) ? [] : explode(',', $raw);
    }

    $limit = nibwp_eventin_clamp($input['per_page'] ?? null, NIBWP_EVENTIN_DEFAULT_ROWS, NIBWP_EVENTIN_MAX_ROWS);
    $schedules = [];
    $skipped = 0;

    foreach (is_array($raw) ? $raw : [] as $value) {
        if (count($schedules) >= $limit) {
            break;
        }

        if (!is_scalar($value)) {
            continue;
        }

        $schedule_id = (int) $value;

        if ($schedule_id <= 0) {
            continue;
        }

        $schedule = get_post($schedule_id);

        if (!$schedule instanceof WP_Post || nibwp_eventin_type('schedule') !== $schedule->post_type) {
            continue;
        }

        if (!nibwp_eventin_can_edit($schedule_id)) {
            $skipped++;
            continue;
        }

        $slots = nibwp_eventin_schedule_slots($schedule_id);
        $schedules[] = array_merge(
            nibwp_eventin_schedule_row($schedule),
            ['slots' => $slots, 'slot_count' => count($slots)]
        );
    }

    return [
        'event_id' => $id,
        'schedules' => $schedules,
        'total' => count($schedules),
        'per_page' => $limit,
        'skipped' => $skipped,
        'skipped_note' => nibwp_eventin_skipped_note($skipped),
    ];
}

/* ──────────────────────── tickets and availability ───────────────────────── */

/** The ticket types on one event. */
function nibwp_eventin_list_ticket_types(array $input): array|WP_Error
{
    $resolved = nibwp_eventin_resolve((int) ($input['event_id'] ?? 0), 'event');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $id = (int) $resolved['post']->ID;

    $denied = nibwp_eventin_require_post_read($id, __('Reading ticket types', 'nibwp'), NIBWP_EVENTIN_CAP_EVENT);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $tickets = nibwp_eventin_tickets_of($id);

    return [
        'event_id' => $id,
        'ticket_types' => $tickets,
        'total' => count($tickets),
    ];
}

/**
 * What is actually left to sell on one event.
 *
 * Eventin keeps two answers: a per-variation `etn_avaiilable_tickets` and, when
 * global stock is on, a single `etn_global_stock` for the whole event
 * (core/event/event-model.php:105-106). Both are reported, plus which one the
 * event is actually using, because an owner looking at a sold-out event needs to
 * know which number is the one doing the blocking.
 */
function nibwp_eventin_ticket_availability(array $input): array|WP_Error
{
    $resolved = nibwp_eventin_resolve((int) ($input['event_id'] ?? 0), 'event');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $id = (int) $resolved['post']->ID;

    $denied = nibwp_eventin_require_post_read($id, __('Reading ticket availability', 'nibwp'), NIBWP_EVENTIN_CAP_EVENT);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $tickets = nibwp_eventin_tickets_of($id);
    $global_on = nibwp_eventin_truthy(get_post_meta($id, 'etn_enable_global_stock', true));
    $available = 0;
    $sold = 0;
    $stock = 0;

    foreach ($tickets as $ticket) {
        $available += (int) ($ticket['available'] ?? 0);
        $sold += (int) ($ticket['sold'] ?? 0);
        $stock += (int) ($ticket['stock'] ?? 0);
    }

    $deadline = get_post_meta($id, 'etn_registration_deadline', true);
    $today = nibwp_eventin_today();

    return [
        'event_id' => $id,
        'stock_mode' => $global_on ? 'global' : 'per_ticket_type',
        'global_stock' => (int) get_post_meta($id, 'etn_global_stock', true),
        'event_total_available' => (int) get_post_meta($id, 'etn_total_avaiilable_tickets', true),
        'event_total_sold' => (int) get_post_meta($id, 'etn_total_sold_tickets', true),
        'variation_available' => $available,
        'variation_sold' => $sold,
        'variation_stock' => $stock,
        'ticket_type_count' => count($tickets),
        'sold_out' => $tickets !== [] && $available < 1,
        'registration_deadline' => is_scalar($deadline) ? (string) $deadline : '',
        'registration_closed' => is_scalar($deadline) && '' !== (string) $deadline && (string) $deadline < $today,
        'ticket_types' => $tickets,
    ];
}

/* ──────────────────────────────── attendees ──────────────────────────────── */

/**
 * The attendee post ids for an event, bounded.
 *
 * Used by the permanent delete to report what it is about to orphan, so it takes
 * no capability of its own — the caller has already cleared `delete_post` on the
 * event, and only ids come back, never a name or an email.
 */
function nibwp_eventin_attendee_ids_for_event(int $event_id, int $limit): array
{
    // Deliberately NOT 'fields' => 'ids': that makes get_posts() return plain ints,
    // which nibwp_eventin_query_posts() filters out as not-a-WP_Post, and the count
    // would silently be zero on every site — the delete would then promise it
    // orphaned nothing.
    $posts = nibwp_eventin_query_posts([
        'post_type' => nibwp_eventin_type('attendee'),
        'post_status' => ['publish', 'draft', 'pending', 'private', 'trash'],
        'posts_per_page' => $limit,
        'suppress_filters' => false,
        'meta_query' => [
            [
                'key' => 'etn_event_id',
                'value' => (string) $event_id,
                'compare' => '=',
            ],
        ],
    ]);

    $ids = [];

    foreach ($posts as $post) {
        $ids[] = (int) $post->ID;
    }

    return $ids;
}

/** The slim shape a listing uses for one attendee. */
function nibwp_eventin_attendee_row(WP_Post $attendee): array
{
    $id = (int) $attendee->ID;

    return [
        'id' => $id,
        'name' => (string) get_post_meta($id, 'etn_name', true),
        'email' => (string) get_post_meta($id, 'etn_email', true),
        'event_id' => (int) get_post_meta($id, 'etn_event_id', true),
        'order_id' => (int) get_post_meta($id, 'eventin_order_id', true),
        'ticket_name' => (string) get_post_meta($id, 'ticket_name', true),
        'ticket_slug' => (string) get_post_meta($id, 'ticket_slug', true),
        'payment_status' => (string) get_post_meta($id, 'etn_status', true),
        'checkin_status' => (string) get_post_meta($id, 'etn_attendeee_ticket_status', true),
    ];
}

/**
 * Attendees, bounded, behind Eventin's own attendee capability.
 *
 * `etn_manage_attendee` and nothing weaker. These rows carry a stranger's name,
 * email address and phone number, and that is exactly the gate Eventin puts on
 * its own attendee screen and controller.
 */
function nibwp_eventin_list_attendees(array $input): array|WP_Error
{
    $denied = nibwp_eventin_require(__('Listing attendees', 'nibwp'), NIBWP_EVENTIN_CAP_ATTENDEE);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $per_page = nibwp_eventin_clamp($input['per_page'] ?? null, NIBWP_EVENTIN_DEFAULT_ROWS, NIBWP_EVENTIN_MAX_ROWS);
    $page = nibwp_eventin_clamp($input['page'] ?? null, 1, 1000);
    $meta_query = [];

    if (isset($input['event_id']) && (int) $input['event_id'] > 0) {
        $event = nibwp_eventin_resolve((int) $input['event_id'], 'event');

        if ($event instanceof WP_Error) {
            return $event;
        }

        $meta_query[] = [
            'key' => 'etn_event_id',
            'value' => (string) (int) $input['event_id'],
            'compare' => '=',
        ];
    }

    if (isset($input['payment_status']) && is_string($input['payment_status']) && '' !== trim($input['payment_status'])) {
        $meta_query[] = [
            'key' => 'etn_status',
            'value' => sanitize_text_field($input['payment_status']),
            'compare' => '=',
        ];
    }

    if (isset($input['checkin_status']) && is_string($input['checkin_status']) && '' !== trim($input['checkin_status'])) {
        $meta_query[] = [
            'key' => 'etn_attendeee_ticket_status',
            'value' => sanitize_text_field($input['checkin_status']),
            'compare' => '=',
        ];
    }

    $args = [
        'post_type' => nibwp_eventin_type('attendee'),
        'post_status' => nibwp_eventin_statuses($input['status'] ?? null),
        'posts_per_page' => $per_page,
        'paged' => $page,
        'suppress_filters' => false,
    ];

    if ($meta_query !== []) {
        $meta_query['relation'] = 'AND';
        $args['meta_query'] = $meta_query;
    }

    $rows = [];

    foreach (nibwp_eventin_query_posts($args) as $attendee) {
        $rows[] = nibwp_eventin_attendee_row($attendee);
    }

    return [
        'attendees' => $rows,
        'total' => count($rows),
        'per_page' => $per_page,
        'page' => $page,
    ];
}

/** One attendee in full, minus the ticket-edit token. */
function nibwp_eventin_get_attendee(array $input): array|WP_Error
{
    $resolved = nibwp_eventin_resolve((int) ($input['attendee_id'] ?? 0), 'attendee');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $denied = nibwp_eventin_require(__('Reading an attendee', 'nibwp'), NIBWP_EVENTIN_CAP_ATTENDEE);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $id = (int) $resolved['post']->ID;
    $meta = nibwp_eventin_read_meta($id, nibwp_eventin_public_attendee_meta());

    return [
        'attendee' => [
            'id' => $id,
            'status' => (string) $resolved['post']->post_status,
            'meta' => $meta['values'],
        ],
        'withheld_meta' => $meta['withheld'],
    ];
}

/**
 * How many attendees an event has, by payment status.
 *
 * A count rather than a listing, so the usual answer to "how is the event doing"
 * needs no personal data at all. Still behind `etn_manage_attendee`: a count that
 * changes tells an observer a booking happened, and that is the attendee screen's
 * information to give.
 */
function nibwp_eventin_count_attendees(array $input): array|WP_Error
{
    global $wpdb;

    $denied = nibwp_eventin_require(__('Counting attendees', 'nibwp'), NIBWP_EVENTIN_CAP_ATTENDEE);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
        return nibwp_eventin_err('plugin_error', __('No database handle is available.', 'nibwp'), 500);
    }

    $event_id = (int) ($input['event_id'] ?? 0);

    if ($event_id > 0) {
        $event = nibwp_eventin_resolve($event_id, 'event');

        if ($event instanceof WP_Error) {
            return $event;
        }
    }

    $sql = "SELECT COALESCE(s.meta_value, '') AS payment_status, COUNT(*) AS total
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
            INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s
            WHERE p.post_type = %s
              AND p.post_status <> %s
              AND e.meta_value = %s
            GROUP BY payment_status";

    $args = ['etn_status', 'etn_event_id', nibwp_eventin_type('attendee'), 'trash', (string) $event_id];

    if ($event_id < 1) {
        $sql = "SELECT COALESCE(s.meta_value, '') AS payment_status, COUNT(*) AS total
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
                WHERE p.post_type = %s
                  AND p.post_status <> %s
                GROUP BY payment_status";
        $args = ['etn_status', nibwp_eventin_type('attendee'), 'trash'];
    }

    try {
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
    } catch (\Throwable $error) {
        return nibwp_eventin_err('plugin_error', $error->getMessage(), 500);
    }

    $by_status = [];
    $total = 0;

    foreach (is_array($rows) ? $rows : [] as $row) {
        $status = (string) ($row['payment_status'] ?? '');
        $count = (int) ($row['total'] ?? 0);
        $by_status[$status === '' ? 'unset' : $status] = $count;
        $total += $count;
    }

    return [
        'event_id' => $event_id,
        'total' => $total,
        'by_payment_status' => $by_status,
    ];
}

/**
 * Who has arrived and who has not.
 *
 * Eventin marks arrival on the attendee record itself, by flipping
 * `etn_attendeee_ticket_status` from `unused` to something else
 * (core/Attendee/attendee-model.php:36, core/Order/OrderController.php:2020 sets
 * the initial value). So check-in state is a count over that one key — reported
 * as counts, with a bounded sample of ids only, because the question "how many
 * have arrived" does not need anybody's name.
 */
function nibwp_eventin_list_checkins(array $input): array|WP_Error
{
    global $wpdb;

    // The event IS the subject of this action, so its type is proved first and
    // alone — then the capability, then everything else about the request.
    $resolved = nibwp_eventin_resolve((int) ($input['event_id'] ?? 0), 'event');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $event_id = (int) $resolved['post']->ID;
    $denied = nibwp_eventin_require(__('Reading check-in status', 'nibwp'), NIBWP_EVENTIN_CAP_ATTENDEE);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
        return nibwp_eventin_err('plugin_error', __('No database handle is available.', 'nibwp'), 500);
    }

    $limit = nibwp_eventin_clamp($input['per_page'] ?? null, NIBWP_EVENTIN_DEFAULT_ROWS, NIBWP_EVENTIN_MAX_ROWS);

    try {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COALESCE(c.meta_value, %s) AS checkin_status, COUNT(*) AS total
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s
                 LEFT JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s
                 WHERE p.post_type = %s
                   AND p.post_status <> %s
                   AND e.meta_value = %s
                 GROUP BY checkin_status",
                'unused',
                'etn_event_id',
                'etn_attendeee_ticket_status',
                nibwp_eventin_type('attendee'),
                'trash',
                (string) $event_id
            ),
            ARRAY_A
        );
    } catch (\Throwable $error) {
        return nibwp_eventin_err('plugin_error', $error->getMessage(), 500);
    }

    $by_status = [];
    $total = 0;

    foreach (is_array($rows) ? $rows : [] as $row) {
        $status = (string) ($row['checkin_status'] ?? '');
        $count = (int) ($row['total'] ?? 0);
        $by_status[$status === '' ? 'unused' : $status] = $count;
        $total += $count;
    }

    $arrived = $total - (int) ($by_status['unused'] ?? 0);
    $sample = [];

    if (nibwp_eventin_truthy($input['include_ids'] ?? false)) {
        $sample = nibwp_eventin_attendee_ids_for_event($event_id, $limit);
    }

    return [
        'event_id' => $event_id,
        'total' => $total,
        'arrived' => $arrived,
        'not_arrived' => (int) ($by_status['unused'] ?? 0),
        'by_checkin_status' => $by_status,
        'attendee_ids' => $sample,
        'per_page' => $limit,
    ];
}

/* ───────────────────────────────── revenue ───────────────────────────────── */

/**
 * What an event took, by order status.
 *
 * Eventin keeps one `etn-order` post per booking, with the money in
 * `total_price` and the event in `event_id` (core/Order/OrderModel.php:32-45).
 * The statuses are the gateway's own vocabulary and differ per payment method, so
 * rather than hardcode a list of "paid" names this groups by whatever is stored
 * and lets the reader see all of it — a total that quietly excluded a status we
 * had not heard of would be worse than one that shows its working.
 *
 * `payment_id` is never read here: it is the gateway's own transaction handle.
 * Neither is any customer field — this answers "how much", not "from whom".
 */
function nibwp_eventin_event_revenue(array $input): array|WP_Error
{
    global $wpdb;

    $event_id = (int) ($input['event_id'] ?? 0);

    if ($event_id > 0) {
        $resolved = nibwp_eventin_resolve($event_id, 'event');

        if ($resolved instanceof WP_Error) {
            return $resolved;
        }
    }

    $denied = nibwp_eventin_require(__('Reading event revenue', 'nibwp'), NIBWP_EVENTIN_CAP_ORDER);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    if ($event_id < 1) {
        return nibwp_eventin_err('bad_request', __('A positive event_id is required.', 'nibwp'));
    }

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
        return nibwp_eventin_err('plugin_error', __('No database handle is available.', 'nibwp'), 500);
    }

    try {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COALESCE(s.meta_value, %s) AS order_status,
                        COUNT(*) AS orders,
                        COALESCE(SUM(CAST(t.meta_value AS DECIMAL(18,4))), 0) AS gross
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s
                 LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
                 LEFT JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = %s
                 WHERE p.post_type = %s
                   AND p.post_status <> %s
                   AND e.meta_value = %s
                 GROUP BY order_status",
                'unset',
                'event_id',
                'status',
                'total_price',
                nibwp_eventin_type('order'),
                'trash',
                (string) $event_id
            ),
            ARRAY_A
        );
    } catch (\Throwable $error) {
        return nibwp_eventin_err('plugin_error', $error->getMessage(), 500);
    }

    $by_status = [];
    $orders = 0;
    $gross = 0.0;

    foreach (is_array($rows) ? $rows : [] as $row) {
        $status = (string) ($row['order_status'] ?? '');
        $status = '' === $status ? 'unset' : $status;
        $count = (int) ($row['orders'] ?? 0);
        $sum = (float) ($row['gross'] ?? 0);
        $by_status[$status] = ['orders' => $count, 'gross' => $sum];
        $orders += $count;
        $gross += $sum;
    }

    $currency = get_post_meta($event_id, 'etn_event_currency', true);

    return [
        'event_id' => $event_id,
        'order_count' => $orders,
        'gross_all_statuses' => $gross,
        'by_order_status' => $by_status,
        'currency' => is_scalar($currency) ? (string) $currency : '',
        'note' => __('Grouped by the order status Eventin stored, not by a hardcoded list of paid statuses: a total that silently dropped a status we had not seen would understate the event. Gateway transaction ids and customer details are never read by this action.', 'nibwp'),
    ];
}

/* ──────────────────────────────── settings ───────────────────────────────── */

/**
 * Eventin's settings, with every credential named and withheld.
 *
 * The whole configuration lives in ONE flat option array, so the allow-list is
 * the only tractable shape: naming the two dozen fields that are safe is
 * possible, guessing every field a future add-on will add is not.
 */
function nibwp_eventin_event_settings(array $input): array|WP_Error
{
    $denied = nibwp_eventin_require(__('Reading Eventin settings', 'nibwp'), NIBWP_EVENTIN_CAP_EVENT);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $options = get_option('etn_event_options', []);

    if (!is_array($options)) {
        $options = [];
    }

    $allow = nibwp_eventin_public_settings();
    $values = [];
    $withheld = [];

    foreach ($options as $key => $value) {
        $key = (string) $key;

        if (isset($allow[$key])) {
            $cast = nibwp_eventin_cast($value, $allow[$key]);

            if ($cast !== null) {
                $values[$key] = $cast;
                continue;
            }

            // Allow-listed but not scalar: dropped rather than cast, and named, so
            // the reader is not told "Array" and left to guess.
            $withheld[] = ['key' => $key, 'is_set' => true, 'value_returned' => false, 'why' => 'not_scalar'];
            continue;
        }

        $is_set = is_array($value) ? $value !== [] : (is_scalar($value) && '' !== (string) $value);
        $withheld[] = [
            'key' => $key,
            'is_set' => $is_set,
            'value_returned' => false,
            'why' => in_array($key, nibwp_eventin_secret_settings(), true) ? 'credential' : 'not_allow_listed',
        ];
    }

    return [
        'settings' => $values,
        'withheld_settings' => $withheld,
        'eventin_version' => nibwp_eventin_version(),
        'note' => __('Settings leave here through an allow-list. Payment, mail, map and meeting credentials are reported as a name and whether they are set, and their values never travel.', 'nibwp'),
    ];
}

/* ───────────────────────────────── audit ─────────────────────────────────── */

/**
 * Run one audit query and keep only the rows this account may see.
 *
 * `$skipped` is by reference because a listing that silently contained fewer rows
 * than it found would be a lie, and the caller reports the number.
 */
function nibwp_eventin_audit_rows(string $sql, string $why, int &$skipped): array
{
    global $wpdb;

    try {
        $rows = $wpdb->get_results($sql, ARRAY_A);
    } catch (\Throwable $error) {
        return ['why' => $why, 'count' => 0, 'items' => [], 'error' => $error->getMessage()];
    }

    $items = [];

    foreach (is_array($rows) ? $rows : [] as $row) {
        $id = (int) ($row['ID'] ?? 0);

        if ($id <= 0) {
            continue;
        }

        if (!nibwp_eventin_can_edit($id)) {
            $skipped++;
            continue;
        }

        $item = ['id' => $id, 'title' => (string) ($row['post_title'] ?? '')];

        foreach (['post_status' => 'status', 'start_date' => 'start_date', 'end_date' => 'end_date', 'available' => 'available', 'sold' => 'sold'] as $from => $to) {
            if (isset($row[$from])) {
                $item[$to] = (string) $row[$from];
            }
        }

        $items[] = $item;
    }

    return ['why' => $why, 'count' => count($items), 'items' => $items];
}

/**
 * What is wrong with this calendar.
 *
 * Every finding here is something that makes an event behave wrongly in public
 * while looking fine in wp-admin, which is the only kind of calendar fault worth
 * an audit action. The malformed-date finding is the important one: it uses a SQL
 * LIKE mask rather than a regex so it works on every MySQL and MariaDB this
 * plugin supports, and `_` matches exactly one character, so `____-__-__` accepts
 * `2026-06-15` and rejects `15/06/2026`, `6/15/26` and `June 15 2026` — the values
 * that cast to NULL inside the calendar's own DATE comparisons and make the event
 * vanish from the archive.
 */
function nibwp_eventin_audit(array $input): array|WP_Error
{
    global $wpdb;

    $denied = nibwp_eventin_require(__('Auditing the calendar', 'nibwp'), NIBWP_EVENTIN_CAP_EVENT);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
        return nibwp_eventin_err('plugin_error', __('No database handle is available.', 'nibwp'), 500);
    }

    $limit = nibwp_eventin_clamp($input['per_page'] ?? null, 50, NIBWP_EVENTIN_MAX_ROWS);
    $event_type = nibwp_eventin_type('event');
    $attendee_type = nibwp_eventin_type('attendee');
    $today = nibwp_eventin_today();

    $findings = [];
    $skipped = 0;

    // 1. Published with no start date at all. The event exists, lists in wp-admin,
    // and no calendar query can ever match it.
    $findings['published_without_date'] = nibwp_eventin_audit_rows(
        $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_status
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
             WHERE p.post_type = %s
               AND p.post_status = %s
               AND (s.meta_value IS NULL OR s.meta_value = %s)
             ORDER BY p.ID DESC
             LIMIT %d",
            'etn_start_date',
            $event_type,
            'publish',
            '',
            $limit
        ),
        __('Published events with no start date. Eventin has nothing to compare, so they never appear on the calendar or in the archive.', 'nibwp'),
        $skipped
    );

    // 2. A date in a shape MySQL cannot cast. This is the silent one.
    $findings['malformed_start_date'] = nibwp_eventin_audit_rows(
        $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_status, s.meta_value AS start_date
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
             WHERE p.post_type = %s
               AND p.post_status NOT IN (%s, %s)
               AND s.meta_value <> %s
               AND s.meta_value NOT LIKE %s
             ORDER BY p.ID DESC
             LIMIT %d",
            'etn_start_date',
            $event_type,
            'trash',
            'auto-draft',
            '',
            '____-__-__',
            $limit
        ),
        __('Events whose start date is not Y-m-d. MySQL casts these to NULL, so they drop out of every calendar range query: the event exists and is invisible.', 'nibwp'),
        $skipped
    );

    // 3. Ends before it starts. The editor makes both fields required, so a row
    // here came in another way — a CSV import, a migration, a direct meta write.
    $findings['ends_before_start'] = nibwp_eventin_audit_rows(
        $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_status, s.meta_value AS start_date, e.meta_value AS end_date
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
             INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s
             WHERE p.post_type = %s
               AND p.post_status NOT IN (%s, %s)
               AND e.meta_value <> %s
               AND e.meta_value < s.meta_value
             ORDER BY p.ID DESC
             LIMIT %d",
            'etn_start_date',
            'etn_end_date',
            $event_type,
            'trash',
            'auto-draft',
            '',
            $limit
        ),
        __('Events whose end is before their start. These sort and display wrongly everywhere, and report as expired the moment they are created.', 'nibwp'),
        $skipped
    );

    // 4. Published, with a date, and nothing to sell.
    $findings['published_without_tickets'] = nibwp_eventin_audit_rows(
        $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_status, s.meta_value AS start_date
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = %s
             WHERE p.post_type = %s
               AND p.post_status = %s
               AND (v.meta_value IS NULL OR v.meta_value = %s OR v.meta_value = %s)
             ORDER BY p.ID DESC
             LIMIT %d",
            'etn_start_date',
            'etn_ticket_variations',
            $event_type,
            'publish',
            '',
            'a:0:{}',
            $limit
        ),
        __('Published events with no ticket types. Visitors reach a registration form with nothing to choose.', 'nibwp'),
        $skipped
    );

    // 5. Sold out and still advertising. The event page still invites bookings;
    // the only thing stopping them is the stock check at the last step.
    $findings['sold_out_still_advertised'] = nibwp_eventin_audit_rows(
        $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_status, a.meta_value AS available, d.meta_value AS sold
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = %s
             INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s
             WHERE p.post_type = %s
               AND p.post_status = %s
               AND CAST(a.meta_value AS SIGNED) < 1
               AND e.meta_value LIKE %s
               AND e.meta_value >= %s
             ORDER BY p.ID DESC
             LIMIT %d",
            'etn_total_avaiilable_tickets',
            'etn_total_sold_tickets',
            'etn_end_date',
            $event_type,
            'publish',
            '____-__-__',
            $today,
            $limit
        ),
        __('Sold-out events still published and still in the future. The listing invites bookings the stock check will refuse at the last step.', 'nibwp'),
        $skipped
    );

    // 6. Over, and still stuck to the front of the site. WordPress keeps sticky
    // ids in an option rather than on the post, so the SQL finds the past events
    // and the membership test happens here.
    $past = nibwp_eventin_audit_rows(
        $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_status, e.meta_value AS end_date
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s
             WHERE p.post_type = %s
               AND p.post_status = %s
               AND e.meta_value LIKE %s
               AND e.meta_value < %s
             ORDER BY e.meta_value DESC
             LIMIT %d",
            'etn_end_date',
            $event_type,
            'publish',
            '____-__-__',
            $today,
            $limit
        ),
        __('Past events still featured on the front of the site.', 'nibwp'),
        $skipped
    );

    $sticky = get_option('sticky_posts', []);
    $sticky = is_array($sticky) ? array_map('intval', array_filter($sticky, 'is_scalar')) : [];
    $still_featured = [];

    foreach ($past['items'] as $item) {
        if (in_array((int) $item['id'], $sticky, true)) {
            $still_featured[] = $item;
        }
    }

    $findings['past_but_still_featured'] = [
        'why' => $past['why'],
        'count' => count($still_featured),
        'items' => $still_featured,
    ];

    if (isset($past['error'])) {
        $findings['past_but_still_featured']['error'] = $past['error'];
    }

    // 7. Attendees with no ticket. Behind the attendee capability like everything
    // else about attendees — and when the caller does not hold it, the finding says
    // so rather than quietly reporting zero, which would read as "all clear".
    if (!current_user_can(NIBWP_EVENTIN_CAP_ATTENDEE)) {
        $findings['attendees_without_ticket'] = [
            'why' => __('Attendee records with no ticket. Not checked: this account does not hold etn_manage_attendee, and zero would read as all-clear.', 'nibwp'),
            'count' => 0,
            'items' => [],
            'requires_capability' => NIBWP_EVENTIN_CAP_ATTENDEE,
        ];
    } else {
        try {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.ID, e.meta_value AS event_id
                     FROM {$wpdb->posts} p
                     LEFT JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = %s
                     LEFT JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = %s
                     LEFT JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s
                     WHERE p.post_type = %s
                       AND p.post_status <> %s
                       AND (t.meta_value IS NULL OR t.meta_value = %s)
                       AND (u.meta_value IS NULL OR u.meta_value = %s)
                     ORDER BY p.ID DESC
                     LIMIT %d",
                    'ticket_slug',
                    'etn_unique_ticket_id',
                    'etn_event_id',
                    $attendee_type,
                    'trash',
                    '',
                    '',
                    $limit
                ),
                ARRAY_A
            );
        } catch (\Throwable $error) {
            $rows = [];
        }

        $orphans = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $id = (int) ($row['ID'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            // Ids and the event they name, and nothing else: this finding is a
            // count of broken records, not a reason to hand out a guest list.
            $orphans[] = ['id' => $id, 'event_id' => (int) ($row['event_id'] ?? 0)];
        }

        $findings['attendees_without_ticket'] = [
            'why' => __('Attendee records with neither a ticket type nor a ticket id. They cannot be checked in and will not appear on a ticket.', 'nibwp'),
            'count' => count($orphans),
            'items' => $orphans,
        ];
    }

    $total = 0;

    foreach ($findings as $finding) {
        $total += (int) ($finding['count'] ?? 0);
    }

    return [
        'findings' => $findings,
        'total' => $total,
        'per_page' => $limit,
        'checked_against' => $today,
        'eventin_version' => nibwp_eventin_version(),
        'skipped' => $skipped,
        'skipped_note' => nibwp_eventin_skipped_note($skipped),
    ];
}

/* ──────────────────────────── registration ───────────────────────────────── */

wp_register_ability('nibwp/eventin-manage', [
    'label' => __('Eventin – Event Management', 'nibwp'),
    'description' => __(
        'Read and manage Eventin. Events: list_events, get_event, create_event, update_event, set_event_status, trash_event, delete_event_permanently, upcoming_events. People: list_speakers, get_speaker, speaker_events, list_organizers, get_organizer, organizer_events. Agenda: list_schedules, get_schedule, event_schedule. Tickets: list_ticket_types, ticket_availability. Attendees: list_attendees, get_attendee, count_attendees, list_checkins. Money: event_revenue. Configuration: event_settings. And audit_calendar, which reports what is wrong with the calendar. delete_event_permanently is irreversible and destroys the event its attendee records hang off, leaving every booking for it unreadable; it requires confirm: true. Attendee reads need the etn_manage_attendee capability. There is no refund or cancel action.',
        'nibwp'
    ),
    'category' => 'events',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => nibwp_eventin_actions(),
                'description' => 'The action to perform.',
            ],
            'event_id' => ['type' => 'integer', 'description' => 'Event post ID. Checked to really be an Eventin event before any permission is considered.'],
            'schedule_id' => ['type' => 'integer', 'description' => 'Schedule post ID.'],
            'attendee_id' => ['type' => 'integer', 'description' => 'Attendee post ID.'],
            'speaker_id' => ['type' => 'integer', 'description' => 'Speaker USER ID: Eventin stores speakers as users with the etn-speaker role, not as posts.'],
            'organizer_id' => ['type' => 'integer', 'description' => 'Organiser USER ID, with the etn-organizer role.'],
            'event_data' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'content' => ['type' => 'string'],
                    'excerpt' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'description' => 'publish, draft, pending, private or future. New events default to draft.'],
                    'start_date' => ['type' => 'string', 'description' => 'Event-local start. Y-m-d H:i:s, Y-m-d H:i or Y-m-d.'],
                    'end_date' => ['type' => 'string', 'description' => 'Event-local end, same formats. Defaults to the start date.'],
                    'timezone' => ['type' => 'string', 'description' => 'Olson name such as Europe/Rome, or a UTC offset. Defaults to the event\'s, then the site\'s.'],
                    'all_day' => ['type' => 'boolean', 'description' => 'Eventin has no all-day flag: this writes the dates and clears both times, which is how the plugin itself reads an all-day event.'],
                    'location' => ['type' => 'string'],
                    'location_type' => ['type' => 'string'],
                    'event_type' => ['type' => 'string'],
                    'registration_deadline' => ['type' => 'string', 'description' => 'Y-m-d, in the event\'s timezone.'],
                ],
                'description' => 'Event fields for create_event and update_event.',
            ],
            'status' => ['type' => 'string', 'description' => 'Status filter for listings, or the new status for set_event_status.'],
            'from_date' => ['type' => 'string', 'description' => 'Range start. Y-m-d H:i:s, Y-m-d H:i or Y-m-d.'],
            'to_date' => ['type' => 'string', 'description' => 'Range end, same formats.'],
            'timezone' => ['type' => 'string', 'description' => 'Timezone the range dates are written in.'],
            'search' => ['type' => 'string', 'description' => 'Title search for event listings, name search for speakers and organisers.'],
            'payment_status' => ['type' => 'string', 'description' => 'Attendee payment status filter, e.g. success or failed.'],
            'checkin_status' => ['type' => 'string', 'description' => 'Attendee check-in filter. Eventin writes "unused" until the ticket is scanned.'],
            'include_ids' => ['type' => 'boolean', 'description' => 'list_checkins returns counts only unless this is true, and even then only ids.'],
            'confirm' => ['type' => 'boolean', 'description' => 'Required by delete_event_permanently, which is irreversible.'],
            'count' => ['type' => 'integer', 'description' => 'How many upcoming events to return.'],
            'per_page' => ['type' => 'integer', 'default' => NIBWP_EVENTIN_DEFAULT_ROWS, 'description' => 'Clamped to ' . NIBWP_EVENTIN_MAX_ROWS . '.'],
            'page' => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'events' => ['type' => 'array'],
            'event' => ['type' => 'object'],
            'speakers' => ['type' => 'array'],
            'speaker' => ['type' => 'object'],
            'organizers' => ['type' => 'array'],
            'organizer' => ['type' => 'object'],
            'schedules' => ['type' => 'array'],
            'schedule' => ['type' => 'object'],
            'ticket_types' => ['type' => 'array'],
            'attendees' => ['type' => 'array'],
            'attendee' => ['type' => 'object'],
            'settings' => ['type' => 'object'],
            'findings' => ['type' => 'object'],
            'by_payment_status' => ['type' => 'object'],
            'by_checkin_status' => ['type' => 'object'],
            'by_order_status' => ['type' => 'object'],
            'total' => ['type' => 'integer'],
            'skipped' => ['type' => 'integer'],
            'skipped_note' => ['type' => 'string'],
            'withheld_meta' => ['type' => 'array'],
            'withheld_settings' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => 'nibwp_eventin_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Read and manage Eventin: events, speakers, organisers, schedules, ticket types, attendees and revenue.',
                '',
                'EVENTS:',
                '- list_events: events overlapping a date range, with a status filter. from_date/to_date/status/search, per_page, page.',
                '- get_event: one event in full, with its ticket types, linked speakers and organisers, and which date meta it is missing.',
                '- create_event: needs event_data.title and event_data.start_date. Starts as a DRAFT unless event_data.status says otherwise.',
                '- update_event: requires event_id and event_data.',
                '- set_event_status: publish, draft, pending, private or future. Refuses to publish an event whose date meta is incomplete, because such an event exists and is invisible.',
                '- trash_event: reversible. Prefer this.',
                '- delete_event_permanently: IRREVERSIBLE and it DESTROYS THE EVENT ITS ATTENDEES ARE ATTACHED TO. Eventin links attendees to an event by meta, not by post parent, so WordPress does not cascade: every attendee record survives pointing at an event that no longer exists, and the booking history for that event is unreadable. It requires confirm: true and reports how many records it orphaned. Ask the owner before calling it.',
                '- upcoming_events: the next count events whose end date is today or later.',
                '',
                'PEOPLE: Eventin stores speakers and organisers as WORDPRESS USERS with the etn-speaker and etn-organizer roles, not as posts, so speaker_id and organizer_id are USER ids. list_speakers, get_speaker, speaker_events, list_organizers, get_organizer, organizer_events. All six take etn_manage_organizer, which is the capability Eventin puts on that screen.',
                '',
                'AGENDA: list_schedules, get_schedule (with its slots), event_schedule (the schedules one event names). All take etn_manage_schedule.',
                '',
                'TICKETS: list_ticket_types and ticket_availability, which reports per-variation stock, global stock, which mode the event is in, whether it is sold out and whether registration has closed.',
                '',
                'ATTENDEES ARE PERSONAL DATA. list_attendees, get_attendee, count_attendees and list_checkins all require etn_manage_attendee — the capability Eventin gates its own attendee screen and REST controller on, not edit_posts. Prefer count_attendees and list_checkins, which answer with counts and need no names at all. Every listing is bounded and cannot be raised past ' . NIBWP_EVENTIN_MAX_ROWS . '.',
                '',
                'MONEY: event_revenue takes etn_manage_order and reports order counts and gross by whatever status Eventin stored. There is NO refund and NO cancel action here, deliberately: money leaving a merchant account is a decision for a person in the booking screen, not a side effect of a tool call.',
                '',
                'DATES: send event-local time plus a timezone, never UTC. Accepted formats are Y-m-d H:i:s, Y-m-d\\TH:i:s, Y-m-d H:i, Y-m-d\\TH:i and Y-m-d, and nothing else — "next Friday" or "15/06/2026" is refused rather than guessed. Eventin stores five meta values that only mean something together (etn_start_date, etn_end_date, etn_start_time, etn_end_time, event_timezone) and its calendar queries CAST the two date keys to a MySQL DATE, so a date in any other shape casts to NULL and the event never appears. All five are written together or none is. An end before its start is refused, not reordered. There is no all-day flag: all_day writes the dates and clears both times.',
                '',
                'IDS: an id is checked to really be of the expected post type — or, for a speaker or organiser, to really hold that role — before any permission is considered, so handing an event action the id of a page refuses on the type and writes nothing.',
                '',
                'PERMISSIONS: the capability is checked BEFORE the rest of the request is validated, so a caller with no authority cannot learn which ids or which status values exist by reading the refusal. Every event write also asks edit_post or delete_post for that specific post. A listing that silently contained fewer rows would be a lie, so it reports skipped and skipped_note.',
                '',
                'CREDENTIALS: Eventin keeps its PayPal, Stripe, Zoom, Google and mail keys in one flat settings array next to harmless display options. event_settings reports allow-listed fields by value and every other field as a name plus whether it is set. Event, attendee and schedule meta travels through the same kind of allow-list, reported under withheld_meta. A per-attendee etn_info_edit_token is never returned: it authenticates an unauthenticated ticket-edit URL. A value that is not scalar is dropped rather than cast, so nothing arrives as the word "Array".',
                '',
                'AUDIT: audit_calendar reports published events with no date, events whose start date is in a shape MySQL cannot cast (the fault that makes an event invisible), ends before starts, published events with no tickets, sold-out events still advertised, past events still featured, and attendee records with no ticket.',
                '',
                'REQUIRES: Eventin must be active.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * The hub.
 *
 * The guard answers first and with its own code, so "the plugin is missing" can
 * never be confused with "the request was wrong" — and no branch below has to
 * wonder whether Eventin is there.
 */
function nibwp_eventin_manage(array $input): array|WP_Error
{
    $guard = nibwp_eventin_guard();

    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $action = is_string($input['action'] ?? null) ? $input['action'] : '';

    if (!in_array($action, nibwp_eventin_actions(), true)) {
        return nibwp_eventin_err(
            'unknown_action',
            sprintf(
                /* translators: 1: the action requested, 2: comma separated list of valid actions */
                __('Unknown action "%1$s". This hub offers: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_eventin_actions())
            )
        );
    }

    try {
        return nibwp_eventin_dispatch($action, $input);
    } catch (\Throwable $error) {
        // A throw from inside Eventin is a refusal, not a 500 that takes the whole
        // REST request with it.
        return nibwp_eventin_err('plugin_error', $error->getMessage(), 500);
    }
}

/** One arm per action, and the check asserts the enum and this list agree. */
function nibwp_eventin_dispatch(string $action, array $input): array|WP_Error
{
    return match ($action) {
        'list_events' => nibwp_eventin_list_events($input),
        'get_event' => nibwp_eventin_get_event($input),
        'create_event' => nibwp_eventin_create_event($input),
        'update_event' => nibwp_eventin_update_event($input),
        'set_event_status' => nibwp_eventin_set_event_status($input),
        'trash_event' => nibwp_eventin_trash_event($input),
        'delete_event_permanently' => nibwp_eventin_delete_event_permanently($input),
        'upcoming_events' => nibwp_eventin_upcoming($input),
        'list_speakers' => nibwp_eventin_list_people('speaker', $input),
        'get_speaker' => nibwp_eventin_get_person('speaker', $input),
        'speaker_events' => nibwp_eventin_person_events('speaker', $input),
        'list_organizers' => nibwp_eventin_list_people('organizer', $input),
        'get_organizer' => nibwp_eventin_get_person('organizer', $input),
        'organizer_events' => nibwp_eventin_person_events('organizer', $input),
        'list_schedules' => nibwp_eventin_list_schedules($input),
        'get_schedule' => nibwp_eventin_get_schedule($input),
        'event_schedule' => nibwp_eventin_event_schedule($input),
        'list_ticket_types' => nibwp_eventin_list_ticket_types($input),
        'ticket_availability' => nibwp_eventin_ticket_availability($input),
        'list_attendees' => nibwp_eventin_list_attendees($input),
        'get_attendee' => nibwp_eventin_get_attendee($input),
        'count_attendees' => nibwp_eventin_count_attendees($input),
        'list_checkins' => nibwp_eventin_list_checkins($input),
        'event_revenue' => nibwp_eventin_event_revenue($input),
        'event_settings' => nibwp_eventin_event_settings($input),
        'audit_calendar' => nibwp_eventin_audit($input),
        default => nibwp_eventin_err('unknown_action', __('Unknown action.', 'nibwp')),
    };
}
