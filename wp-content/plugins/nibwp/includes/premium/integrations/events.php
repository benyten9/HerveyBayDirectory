<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: The Events Calendar.
 *
 * Verified against The Events Calendar 6.18.0 (the-events-calendar.php:5).
 * `Tribe__Events__Main` is still the entry point — it is the class that owns the
 * post type and taxonomy constants (src/Tribe/Main.php:39-42) and the meta key
 * list every writer in the plugin loops over (src/Tribe/Main.php:213-238), so
 * its absence is the honest test for "not installed".
 *
 * WRITES GO THROUGH THE ORM, NOT THE LEGACY FUNCTIONS, and this is the single
 * most important decision in the file. `tribe_create_event()` and
 * `tribe_update_event()` still exist, and their own docblocks say they are
 * outdated and to use `tribe_events()->create()` / `->save()` instead
 * (src/functions/advanced-functions/event.php:18 and :107). That is not merely
 * stylistic. The legacy path reaches `Tribe__Events__API::prepare_event_date_meta()`,
 * which does two things that quietly ruin an event written by an agent:
 *
 *   - it reinterprets the date string through the SITE'S DATEPICKER SETTING,
 *     `Tribe__Date_Utils::datetime_from_format( tribe_get_option('datepickerFormat'), … )`
 *     (src/Tribe/API.php:383-391), so the same `2026-06-15 09:00:00` means
 *     different days on two sites;
 *   - it only believes a date was supplied when a TIME came with it as separate
 *     `EventStartTime` or `EventStartHour`+`EventStartMinute` fields
 *     (src/Tribe/API.php:400-405). Pass only `EventStartDate`/`EventEndDate` — the
 *     obvious thing to pass, and what this integration used to pass — and
 *     `$date_provided` stays false, at which point lines 437-441 overwrite both
 *     dates with the event's EXISTING meta, which on a fresh insert is empty.
 *     The function then returns early (line 460) before writing
 *     `_EventStartDateUTC`, `_EventEndDateUTC` or `_EventDuration` at all. The
 *     post exists, wp-admin lists it, and the calendar never shows it.
 *
 * The ORM's `update_date_meta()` (src/Tribe/Repositories/Event.php:1145-1310)
 * writes the whole coherent set from the local date plus the timezone, every
 * time. So that is what this file uses.
 *
 * DATES AND TIMEZONES. An event is stored in EVENT-LOCAL time plus a named
 * timezone, with a UTC mirror alongside — not in UTC alone. Both pairs have to be
 * written, because the repository CHOOSES WHICH PAIR TO QUERY from a site setting:
 * `Timezones::is_mode('site')` picks `_EventStartDateUTC`/`_EventEndDateUTC`,
 * anything else picks `_EventStartDate`/`_EventEndDate`
 * (src/Tribe/Repositories/Event.php:95-102). Write only the local pair and the
 * event is invisible on every site using site-wide timezone mode; write only the
 * UTC pair and it is invisible on the rest. `nibwp_events_required_date_meta()`
 * below is that full set, and the check asserts every key in it by name.
 *
 * A date from a caller is text, so it is parsed strictly against a short list of
 * named formats and refused with `nibwp_events_bad_date` when it does not match.
 * No `strtotime()` anywhere: `strtotime('next thursday')` succeeds, and an agent
 * guessing is how an event lands in the wrong week. An end before its start is
 * refused with its own code rather than corrected, because both the legacy writer
 * (src/Tribe/API.php:493-497, which silently moves the end to the start) and the
 * ORM (src/Tribe/Repositories/Event.php:1245-1254, which silently drops ALL date
 * meta) would otherwise hide the caller's mistake and produce a dateless event.
 *
 * AN ID FROM A CALLER IS A CLAIM. Every action that takes one resolves the post
 * type FIRST, on its own, before any capability is consulted at all — see
 * `nibwp_events_resolve()`. Handed the id of a page, a delete must refuse on the
 * type and must not have asked whether anyone may delete pages. Then, and only
 * then, `edit_post`/`delete_post` against that specific id. Never `edit_posts`
 * alone: that capability says nothing about the object in hand.
 *
 * CREDENTIALS. This plugin has commercial ticketing add-ons that store gateway
 * keys, ticket stock and attendee data in post meta next to the event's own. Meta
 * leaves here through an ALLOW-LIST of keys and nothing else, and every other key
 * found on the post is reported by name with `value_returned` false, so a caller
 * can see that something is there without being handed it. An allow-list because
 * the add-ons add keys we have never seen on a schedule we do not control, and a
 * denylist would default to leaking each new one.
 */

/**
 * The door. Opening it is not permission to touch anything in the room: every
 * per-object decision is taken again against that object.
 */
const NIBWP_EVENTS_CAP = 'edit_posts';

/** How many rows any listing returns, whatever was asked for. */
const NIBWP_EVENTS_MAX_ROWS = 200;

/** How far ahead `upcoming_events` and the calendar read will look by default. */
const NIBWP_EVENTS_DEFAULT_ROWS = 20;

/** The database format every date in this plugin is stored in. */
const NIBWP_EVENTS_DB_FORMAT = 'Y-m-d H:i:s';

/**
 * Is The Events Calendar actually loaded?
 *
 * `Tribe__Events__Main` carries the post type constants and the meta tag list, so
 * nothing in this file can do useful work without it.
 */
function nibwp_events_guard(): ?WP_Error
{
    if (!class_exists('Tribe__Events__Main')) {
        return new WP_Error(
            'nibwp_events_missing',
            __('The Events Calendar is not active on this site.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/** A refusal about the request itself. */
function nibwp_events_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error('nibwp_events_' . $code, $message, ['status' => $status]);
}

/** A refusal about authority rather than about the request. */
function nibwp_events_forbidden(string $what, string $cap): WP_Error
{
    return new WP_Error(
        'nibwp_events_forbidden',
        sprintf(
            /* translators: 1: what was being attempted, 2: capability name */
            __('%1$s needs the %2$s capability for that specific post, which this account does not have.', 'nibwp'),
            $what,
            $cap
        ),
        ['status' => 403]
    );
}

/** The door, for listings and anything with no single object to ask about. */
function nibwp_events_require(string $what): ?WP_Error
{
    return current_user_can(NIBWP_EVENTS_CAP) ? null : nibwp_events_forbidden($what, NIBWP_EVENTS_CAP);
}

/** Post type names, read off the plugin rather than hardcoded. */
function nibwp_events_type(string $kind): string
{
    if (!class_exists('Tribe__Events__Main')) {
        return '';
    }

    switch ($kind) {
        case 'event':
            return (string) Tribe__Events__Main::POSTTYPE;
        case 'venue':
            return (string) Tribe__Events__Main::VENUE_POST_TYPE;
        case 'organizer':
            return (string) Tribe__Events__Main::ORGANIZER_POST_TYPE;
    }

    return '';
}

/** The event category taxonomy, read off the plugin. */
function nibwp_events_taxonomy(): string
{
    return class_exists('Tribe__Events__Main') ? (string) Tribe__Events__Main::TAXONOMY : '';
}

/** Human names for the three kinds, for refusal messages. */
function nibwp_events_kind_label(string $kind): string
{
    switch ($kind) {
        case 'venue':
            return __('venue', 'nibwp');
        case 'organizer':
            return __('organizer', 'nibwp');
    }

    return __('event', 'nibwp');
}

/**
 * Turn a caller's id into a post of the expected type, and do NOTHING else.
 *
 * No capability is consulted here, deliberately. An id is a claim about an
 * object; until we know the object is the kind the action is about, asking
 * whether anyone may edit it is asking the wrong question — and a `delete_post`
 * check that passes on a page id is how an agent deletes the About page while
 * believing it cancelled an event. The caller checks authority after this returns.
 */
function nibwp_events_resolve(int $id, string $kind): array|WP_Error
{
    $expected = nibwp_events_type($kind);

    if ($id <= 0) {
        return nibwp_events_err(
            'bad_request',
            sprintf(
                /* translators: %s: the kind of record, e.g. event */
                __('A positive %s id is required.', 'nibwp'),
                nibwp_events_kind_label($kind)
            )
        );
    }

    $post = get_post($id);

    if (!$post instanceof WP_Post) {
        return nibwp_events_err(
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
        $code = 'venue' === $kind ? 'not_a_venue' : ('organizer' === $kind ? 'not_an_organizer' : 'not_an_event');

        return nibwp_events_err(
            $code,
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

/** May this account edit this one post? */
function nibwp_events_can_edit(int $id): bool
{
    return current_user_can('edit_post', $id);
}

/** May this account delete this one post? */
function nibwp_events_can_delete(int $id): bool
{
    return current_user_can('delete_post', $id);
}

/** Clamp a caller's count to something this site can afford to build. */
function nibwp_events_clamp(mixed $raw, int $default, int $max): int
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
 * Every meta key that has to be written together for an event to appear.
 *
 * Verified from two places in 6.18.0 rather than from memory: the plugin's own
 * meta tag list (src/Tribe/Main.php:213-238) names them, and the ORM's
 * `update_date_meta()` (src/Tribe/Repositories/Event.php:1145-1310) is what
 * writes them, including the UTC mirror at 1196-1199, the duration at 1256 and
 * the abbreviation at 1304. The repository then queries ONE of the two pairs
 * depending on the site's timezone mode (src/Tribe/Repositories/Event.php:95-102),
 * which is why both pairs are mandatory and not merely nice to have.
 */
function nibwp_events_required_date_meta(): array
{
    return [
        '_EventStartDate',
        '_EventEndDate',
        '_EventStartDateUTC',
        '_EventEndDateUTC',
        '_EventDuration',
        '_EventTimezone',
        '_EventTimezoneAbbr',
    ];
}

/**
 * The date formats a caller may use, in the order they are tried.
 *
 * Short on purpose. Every one of them is unambiguous about which number is the
 * month, which is the single mistake that puts an event six months away without
 * anybody noticing.
 */
function nibwp_events_date_formats(): array
{
    return ['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i', 'Y-m-d'];
}

/**
 * Parse a caller's date strictly, or refuse by name.
 *
 * `createFromFormat()` on its own is not strict: it happily rolls 2026-02-31 into
 * March. So the parsed result is formatted back and compared to the input, and
 * anything that does not survive the round trip is refused. The leading `!` zeroes
 * the fields the format does not mention, so a date with no time means midnight
 * rather than "whatever o'clock it is right now".
 *
 * @return array{local:string,date_only:bool,object:DateTimeImmutable}|WP_Error
 */
function nibwp_events_parse_datetime(mixed $raw, string $field, DateTimeZone $tz): array|WP_Error
{
    if (!is_string($raw) && !is_numeric($raw)) {
        return nibwp_events_err(
            'bad_date',
            sprintf(
                /* translators: 1: input field name, 2: list of accepted formats */
                __('%1$s must be a date string. Accepted formats: %2$s.', 'nibwp'),
                $field,
                implode(', ', nibwp_events_date_formats())
            )
        );
    }

    $text = trim((string) $raw);

    if ($text === '') {
        return nibwp_events_err(
            'bad_date',
            sprintf(
                /* translators: %s: input field name */
                __('%s is empty. A date is required here; it is not guessed.', 'nibwp'),
                $field
            )
        );
    }

    foreach (nibwp_events_date_formats() as $format) {
        $parsed = DateTimeImmutable::createFromFormat('!' . $format, $text, $tz);

        if (!$parsed instanceof DateTimeImmutable) {
            continue;
        }

        // The round trip is the strictness. Without it 2026-02-31 parses, becomes
        // 2026-03-03, and the event is three days late for ever.
        if ($parsed->format($format) !== $text) {
            continue;
        }

        return [
            'local' => $parsed->format(NIBWP_EVENTS_DB_FORMAT),
            'date_only' => 'Y-m-d' === $format,
            'object' => $parsed,
        ];
    }

    return nibwp_events_err(
        'bad_date',
        sprintf(
            /* translators: 1: input field name, 2: the value received, 3: list of accepted formats */
            __('%1$s could not be read as a date: "%2$s". Accepted formats: %3$s. Nothing is guessed here, so please send one of those.', 'nibwp'),
            $field,
            $text,
            implode(', ', nibwp_events_date_formats())
        )
    );
}

/**
 * Which timezone does this event live in?
 *
 * The plugin's own answer, in order: the event's `_EventTimezone` if it has one,
 * otherwise the site's (src/Tribe/Timezones.php:68-72). A caller may override it,
 * and an unusable name is refused rather than silently swapped for UTC — an hour
 * wrong is worse than a refusal, because nobody notices it.
 *
 * @return array{name:string,tz:DateTimeZone}|WP_Error
 */
function nibwp_events_timezone(mixed $raw, ?int $event_id): array|WP_Error
{
    $name = is_string($raw) ? trim($raw) : '';

    if ($name === '' && $event_id !== null && $event_id > 0) {
        $stored = get_post_meta($event_id, '_EventTimezone', true);
        $name = is_string($stored) ? trim($stored) : '';
    }

    if ($name === '') {
        if (class_exists('Tribe__Events__Timezones') && method_exists('Tribe__Events__Timezones', 'wp_timezone_string')) {
            try {
                $name = (string) Tribe__Events__Timezones::wp_timezone_string();
            } catch (\Throwable $error) {
                $name = '';
            }
        }
    }

    if ($name === '' && function_exists('wp_timezone_string')) {
        $name = (string) wp_timezone_string();
    }

    if ($name === '') {
        $option = get_option('timezone_string');
        $name = is_string($option) ? trim($option) : '';
    }

    if ($name === '') {
        $name = 'UTC';
    }

    try {
        $tz = new DateTimeZone($name);
    } catch (\Throwable $error) {
        return nibwp_events_err(
            'bad_timezone',
            sprintf(
                /* translators: %s: the timezone name received */
                __('"%s" is not a timezone PHP recognises. Use a name like Europe/Rome, or a UTC offset like +02:00.', 'nibwp'),
                $name
            )
        );
    }

    return ['name' => $name, 'tz' => $tz];
}

/**
 * Build the whole coherent date/timezone meta set, or refuse.
 *
 * Everything in `nibwp_events_required_date_meta()` is produced here, in one
 * place, from one parse. `_EventAllDay` joins it when the caller asks for an
 * all-day event, which is the only case where the plugin stores a day boundary
 * rather than a time the caller chose.
 *
 * @return array<string, scalar>|WP_Error
 */
function nibwp_events_build_date_meta(array $data, ?int $event_id): array|WP_Error
{
    $zone = nibwp_events_timezone($data['timezone'] ?? '', $event_id);

    if ($zone instanceof WP_Error) {
        return $zone;
    }

    $tz = $zone['tz'];

    $start_raw = $data['start_date'] ?? null;
    $end_raw = $data['end_date'] ?? null;

    // On an update the caller may move one end only, so the other comes from
    // what is stored — which is already in this event's local time.
    if ($start_raw === null && $event_id !== null && $event_id > 0) {
        $stored = get_post_meta($event_id, '_EventStartDate', true);
        $start_raw = is_string($stored) && $stored !== '' ? $stored : null;
    }

    if ($end_raw === null && $event_id !== null && $event_id > 0) {
        $stored = get_post_meta($event_id, '_EventEndDate', true);
        $end_raw = is_string($stored) && $stored !== '' ? $stored : null;
    }

    if ($start_raw === null) {
        return nibwp_events_err('bad_request', __('start_date is required: an event with no start never appears on the calendar.', 'nibwp'));
    }

    $start = nibwp_events_parse_datetime($start_raw, 'start_date', $tz);

    if ($start instanceof WP_Error) {
        return $start;
    }

    // A start with no end and no duration is the commonest shape a caller sends.
    // Two hours is the plugin's own default (tec_occurrences.duration DEFAULT
    // 7200, src/Events/Custom_Tables/V1/Tables/Occurrences.php:71), so matching it
    // beats inventing a number.
    if ($end_raw === null) {
        $end = [
            'local' => $start['object']->modify('+2 hours')->format(NIBWP_EVENTS_DB_FORMAT),
            'date_only' => $start['date_only'],
            'object' => $start['object']->modify('+2 hours'),
        ];
    } else {
        $end = nibwp_events_parse_datetime($end_raw, 'end_date', $tz);

        if ($end instanceof WP_Error) {
            return $end;
        }
    }

    $all_day = false;

    if (array_key_exists('all_day', $data)) {
        $all_day = nibwp_events_truthy($data['all_day']);
    } elseif ($event_id !== null && $event_id > 0) {
        $all_day = nibwp_events_truthy(get_post_meta($event_id, '_EventAllDay', true));
    }

    if ($all_day) {
        // The plugin snaps an all-day event to the site's day boundaries, which
        // are configurable ("multiday cutoff"), so its own helpers answer rather
        // than a hardcoded midnight.
        $start = nibwp_events_day_boundary($start, $tz, 'start');
        $end = nibwp_events_day_boundary($end, $tz, 'end');
    }

    $start_ts = $start['object']->getTimestamp();
    $end_ts = $end['object']->getTimestamp();

    // Refused, not corrected. The legacy writer moves the end to the start
    // (src/Tribe/API.php:493-497) and the ORM throws every date away
    // (src/Tribe/Repositories/Event.php:1245-1254); either way the caller is told
    // it worked and the calendar disagrees.
    if ($end_ts < $start_ts) {
        return nibwp_events_err(
            'end_before_start',
            sprintf(
                /* translators: 1: end date, 2: start date */
                __('end_date %1$s is before start_date %2$s. Refusing rather than reordering them, because a silently corrected date is one nobody checks.', 'nibwp'),
                $end['local'],
                $start['local']
            )
        );
    }

    $utc = new DateTimeZone('UTC');

    $meta = [
        '_EventStartDate' => $start['local'],
        '_EventEndDate' => $end['local'],
        '_EventStartDateUTC' => $start['object']->setTimezone($utc)->format(NIBWP_EVENTS_DB_FORMAT),
        '_EventEndDateUTC' => $end['object']->setTimezone($utc)->format(NIBWP_EVENTS_DB_FORMAT),
        '_EventDuration' => $end_ts - $start_ts,
        '_EventTimezone' => $zone['name'],
        '_EventTimezoneAbbr' => nibwp_events_abbr($start['local'], $zone['name'], $start['object']),
    ];

    if ($all_day) {
        $meta['_EventAllDay'] = 'yes';
    }

    return $meta;
}

/** Snap a parsed date to the start or end of its day, the plugin's way. */
function nibwp_events_day_boundary(array $parsed, DateTimeZone $tz, string $edge): array
{
    $helper = 'start' === $edge ? 'tribe_beginning_of_day' : 'tribe_end_of_day';

    if (function_exists($helper)) {
        try {
            $snapped = (string) call_user_func($helper, $parsed['local']);
            $rebuilt = DateTimeImmutable::createFromFormat('!' . NIBWP_EVENTS_DB_FORMAT, $snapped, $tz);

            if ($rebuilt instanceof DateTimeImmutable) {
                return ['local' => $rebuilt->format(NIBWP_EVENTS_DB_FORMAT), 'date_only' => false, 'object' => $rebuilt];
            }
        } catch (\Throwable $error) {
            // Fall through to the plain boundary below.
        }
    }

    $object = 'start' === $edge
        ? $parsed['object']->setTime(0, 0, 0)
        : $parsed['object']->setTime(23, 59, 59);

    return ['local' => $object->format(NIBWP_EVENTS_DB_FORMAT), 'date_only' => false, 'object' => $object];
}

/** The timezone abbreviation the plugin would store for this moment. */
function nibwp_events_abbr(string $local, string $zone, DateTimeImmutable $moment): string
{
    if (class_exists('Tribe__Timezones') && method_exists('Tribe__Timezones', 'abbr')) {
        try {
            $abbr = (string) Tribe__Timezones::abbr($local, $zone);

            if ($abbr !== '') {
                return $abbr;
            }
        } catch (\Throwable $error) {
            // The plugin's own helper reaches options and can raise mid-update.
        }
    }

    $abbr = $moment->format('T');

    return $abbr === '' ? $zone : $abbr;
}

/** The plugin's idea of truthy, without depending on its helper being loaded. */
function nibwp_events_truthy(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return (int) $value > 0;
    }

    if (!is_string($value)) {
        return false;
    }

    return in_array(strtolower(trim($value)), ['1', 'yes', 'y', 'true', 'on'], true);
}

/**
 * Which meta key does THIS site's calendar sort and filter on?
 *
 * Not a detail: `Timezones::is_mode('site')` swaps the whole repository over to
 * the UTC pair (src/Tribe/Repositories/Event.php:95-102). A read that picks the
 * other pair returns events in an order the calendar does not use.
 */
function nibwp_events_start_key(): string
{
    if (class_exists('Tribe__Events__Timezones') && method_exists('Tribe__Events__Timezones', 'is_mode')) {
        try {
            if (Tribe__Events__Timezones::is_mode('site')) {
                return '_EventStartDateUTC';
            }
        } catch (\Throwable $error) {
            // Fall through: local is the plugin's default mode.
        }
    }

    return '_EventStartDate';
}

/** The end key that pairs with whatever `nibwp_events_start_key()` chose. */
function nibwp_events_end_key(): string
{
    return '_EventStartDateUTC' === nibwp_events_start_key() ? '_EventEndDateUTC' : '_EventEndDate';
}

/** Now, in the same scale as the key the site queries on. */
function nibwp_events_now(): string
{
    return '_EventStartDateUTC' === nibwp_events_start_key()
        ? gmdate(NIBWP_EVENTS_DB_FORMAT)
        : current_time(NIBWP_EVENTS_DB_FORMAT);
}

/** Post statuses a caller may ask for, and what "any" means. */
function nibwp_events_statuses(mixed $raw): array
{
    $allowed = ['publish', 'future', 'draft', 'pending', 'private', 'trash'];

    if (is_string($raw) && 'any' === strtolower(trim($raw))) {
        return $allowed;
    }

    $wanted = is_array($raw) ? $raw : (is_string($raw) && $raw !== '' ? [$raw] : []);
    $clean = [];

    foreach ($wanted as $status) {
        if (!is_string($status)) {
            continue;
        }

        $status = strtolower(trim($status));

        if (in_array($status, $allowed, true)) {
            $clean[] = $status;
        }
    }

    return $clean === [] ? ['publish', 'future'] : array_values(array_unique($clean));
}

/**
 * Event meta that may leave, and how to cast it.
 *
 * Everything else found on the post is reported as a name only. The ticketing
 * add-ons store gateway keys, attendee records and stock counts in this same
 * namespace, and they add keys on their own schedule — so this list is what
 * travels, and growing it is a decision somebody has to make here, in front of
 * the reason why.
 *
 * @return array<string, string>
 */
function nibwp_events_public_event_meta(): array
{
    return [
        '_EventStartDate' => 'string',
        '_EventEndDate' => 'string',
        '_EventStartDateUTC' => 'string',
        '_EventEndDateUTC' => 'string',
        '_EventDuration' => 'int',
        '_EventTimezone' => 'string',
        '_EventTimezoneAbbr' => 'string',
        '_EventAllDay' => 'bool',
        '_EventVenueID' => 'int',
        '_EventOrganizerID' => 'int',
        '_EventCost' => 'string',
        '_EventCostMin' => 'string',
        '_EventCostMax' => 'string',
        '_EventCurrencySymbol' => 'string',
        '_EventCurrencyCode' => 'string',
        '_EventCurrencyPosition' => 'string',
        '_EventURL' => 'string',
        '_EventPhone' => 'string',
        '_EventHideFromUpcoming' => 'bool',
        '_EventShowMap' => 'bool',
        '_EventShowMapLink' => 'bool',
        '_EventOrigin' => 'string',
        '_tribe_featured' => 'bool',
    ];
}

/**
 * Meta key to output name for the two linked post types.
 *
 * The names a caller READS are the names it WRITES, so a round trip does not need
 * a translation table in the agent's head.
 *
 * @return array<string, string>
 */
function nibwp_events_linked_field_map(string $kind): array
{
    if ('venue' === $kind) {
        return [
            '_VenueAddress' => 'address',
            '_VenueCity' => 'city',
            '_VenueState' => 'state',
            '_VenueProvince' => 'province',
            '_VenueStateProvince' => 'state_province',
            '_VenueCountry' => 'country',
            '_VenueZip' => 'zip',
            '_VenuePhone' => 'phone',
            '_VenueURL' => 'website',
            '_VenueShowMap' => 'show_map',
            '_VenueShowMapLink' => 'show_map_link',
        ];
    }

    return [
        '_OrganizerEmail' => 'email',
        '_OrganizerWebsite' => 'website',
        '_OrganizerPhone' => 'phone',
    ];
}

/** Venue meta that may leave. Addresses, nothing else. */
function nibwp_events_public_venue_meta(): array
{
    return [
        '_VenueAddress' => 'string',
        '_VenueCity' => 'string',
        '_VenueState' => 'string',
        '_VenueProvince' => 'string',
        '_VenueStateProvince' => 'string',
        '_VenueCountry' => 'string',
        '_VenueZip' => 'string',
        '_VenuePhone' => 'string',
        '_VenueURL' => 'string',
        '_VenueShowMap' => 'bool',
        '_VenueShowMapLink' => 'bool',
    ];
}

/** Organizer meta that may leave. */
function nibwp_events_public_organizer_meta(): array
{
    return [
        '_OrganizerEmail' => 'string',
        '_OrganizerWebsite' => 'string',
        '_OrganizerPhone' => 'string',
    ];
}

/** Cast one allow-listed meta value to its declared shape. */
function nibwp_events_cast(mixed $value, string $as): mixed
{
    switch ($as) {
        case 'int':
            return (int) $value;
        case 'bool':
            return nibwp_events_truthy($value);
    }

    return is_scalar($value) ? (string) $value : '';
}

/**
 * Read a post's meta through an allow-list, and name what was held back.
 *
 * The withheld list matters as much as the allowed one: an owner asking why an
 * event behaves oddly needs to know a ticketing add-on has attached fourteen keys
 * to it, without any of those fourteen values crossing the wire.
 *
 * @param array<string, string> $allow
 * @return array{values:array<string, mixed>, withheld:array<int, array<string, mixed>>}
 */
function nibwp_events_read_meta(int $post_id, array $allow, array $rename = []): array
{
    $values = [];

    foreach ($allow as $key => $as) {
        $raw = get_post_meta($post_id, $key, true);

        if ($raw === '' || $raw === false || $raw === null) {
            continue;
        }

        $values[$rename[$key] ?? ltrim($key, '_')] = nibwp_events_cast($raw, $as);
    }

    $withheld = [];
    $all = get_post_meta($post_id);

    if (is_array($all)) {
        foreach (array_keys($all) as $key) {
            $key = (string) $key;

            if (isset($allow[$key]) || '_' !== substr($key, 0, 1)) {
                continue;
            }

            $withheld[] = ['key' => $key, 'value_returned' => false];
        }
    }

    return ['values' => $values, 'withheld' => $withheld];
}

/** The slim shape a listing uses for one event. */
function nibwp_events_event_row(WP_Post $event): array
{
    $meta = nibwp_events_read_meta($event->ID, nibwp_events_public_event_meta());
    $values = $meta['values'];

    return [
        'id' => (int) $event->ID,
        'title' => (string) $event->post_title,
        'status' => (string) $event->post_status,
        'url' => (string) get_permalink($event->ID),
        'start_date' => (string) ($values['EventStartDate'] ?? ''),
        'end_date' => (string) ($values['EventEndDate'] ?? ''),
        'start_date_utc' => (string) ($values['EventStartDateUTC'] ?? ''),
        'end_date_utc' => (string) ($values['EventEndDateUTC'] ?? ''),
        'timezone' => (string) ($values['EventTimezone'] ?? ''),
        'timezone_abbr' => (string) ($values['EventTimezoneAbbr'] ?? ''),
        'all_day' => (bool) ($values['EventAllDay'] ?? false),
        'duration_seconds' => (int) ($values['EventDuration'] ?? 0),
        'venue_id' => (int) ($values['EventVenueID'] ?? 0),
        'organizer_id' => (int) ($values['EventOrganizerID'] ?? 0),
        'cost' => (string) ($values['EventCost'] ?? ''),
        'featured' => (bool) ($values['tribe_featured'] ?? false),
        'sticky' => -1 === (int) $event->menu_order,
    ];
}

/** Everything about one event, including who it is linked to. */
function nibwp_events_event_detail(WP_Post $event): array
{
    $meta = nibwp_events_read_meta($event->ID, nibwp_events_public_event_meta());
    $row = nibwp_events_event_row($event);

    $venue_id = $row['venue_id'];
    $organizer_id = $row['organizer_id'];

    $row['description'] = (string) $event->post_content;
    $row['excerpt'] = (string) $event->post_excerpt;
    $row['venue'] = $venue_id > 0 ? nibwp_events_linked_summary($venue_id, 'venue') : null;
    $row['organizer'] = $organizer_id > 0 ? nibwp_events_linked_summary($organizer_id, 'organizer') : null;
    $row['cost_min'] = (string) ($meta['values']['EventCostMin'] ?? '');
    $row['cost_max'] = (string) ($meta['values']['EventCostMax'] ?? '');
    $row['currency_symbol'] = (string) ($meta['values']['EventCurrencySymbol'] ?? '');
    $row['currency_code'] = (string) ($meta['values']['EventCurrencyCode'] ?? '');
    $row['currency_position'] = (string) ($meta['values']['EventCurrencyPosition'] ?? '');
    $row['website'] = (string) ($meta['values']['EventURL'] ?? '');
    $row['phone'] = (string) ($meta['values']['EventPhone'] ?? '');
    $row['hidden_from_upcoming'] = (bool) ($meta['values']['EventHideFromUpcoming'] ?? false);
    $row['categories'] = nibwp_events_terms_of($event->ID, nibwp_events_taxonomy());
    $row['tags'] = nibwp_events_terms_of($event->ID, 'post_tag');
    $row['withheld_meta'] = $meta['withheld'];

    return $row;
}

/** A linked venue or organizer, as much of it as may travel. */
function nibwp_events_linked_summary(int $id, string $kind): array
{
    $post = get_post($id);
    $expected = nibwp_events_type($kind);

    if (!$post instanceof WP_Post || $post->post_type !== $expected) {
        // A dangling link is a finding, not an error: the audit reports it.
        return ['id' => $id, 'exists' => false, 'name' => '', 'readable' => false];
    }

    $allow = 'venue' === $kind ? nibwp_events_public_venue_meta() : nibwp_events_public_organizer_meta();
    $rename = nibwp_events_linked_field_map($kind);
    $readable = nibwp_events_can_edit($id);

    $summary = [
        'id' => $id,
        'exists' => true,
        'name' => (string) $post->post_title,
        'status' => (string) $post->post_status,
        'readable' => $readable,
    ];

    if ($readable) {
        $summary += nibwp_events_read_meta($id, $allow, $rename)['values'];
    }

    return $summary;
}

/** Term names and slugs on one post, without letting a taxonomy error escape. */
function nibwp_events_terms_of(int $post_id, string $taxonomy): array
{
    if ($taxonomy === '') {
        return [];
    }

    try {
        $terms = get_the_terms($post_id, $taxonomy);
    } catch (\Throwable $error) {
        return [];
    }

    if (!is_array($terms)) {
        return [];
    }

    $out = [];

    foreach ($terms as $term) {
        if (!is_object($term)) {
            continue;
        }

        $out[] = [
            'id' => (int) ($term->term_id ?? 0),
            'name' => (string) ($term->name ?? ''),
            'slug' => (string) ($term->slug ?? ''),
        ];
    }

    return $out;
}

/**
 * Run a bounded post query and drop what this account may not edit.
 *
 * The skipped count is not decoration. A listing that silently omits rows reads
 * as "there are three events next month" when there are nine, and an agent
 * reporting that to an owner is wrong in a way neither of them can see.
 *
 * @return array{items:array<int, WP_Post>, skipped:int}
 */
function nibwp_events_query_posts(array $args): array
{
    $args['fields'] = 'all';
    $args['suppress_filters'] = false;
    $args['no_found_rows'] = true;
    $args['ignore_sticky_posts'] = true;

    // The repository marks its own queries this way so the plugin's view filters
    // keep their hands off (src/Tribe/Repositories/Event.php:146).
    $args['tribe_suppress_query_filters'] = true;

    try {
        $posts = get_posts($args);
    } catch (\Throwable $error) {
        $posts = [];
    }

    $items = [];
    $skipped = 0;

    foreach (is_array($posts) ? $posts : [] as $post) {
        if (!$post instanceof WP_Post) {
            continue;
        }

        if (!nibwp_events_can_edit((int) $post->ID)) {
            $skipped++;
            continue;
        }

        $items[] = $post;
    }

    return ['items' => $items, 'skipped' => $skipped];
}

/** The sentence a listing adds when it dropped something. */
function nibwp_events_skipped_note(int $skipped): string
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

/**
 * Does a table exist? Asked before any read of the custom tables.
 *
 * The occurrences table is created by the plugin's own schema manager and can be
 * absent on a site mid-upgrade; a query against a missing table is a database
 * error in the log and an empty answer either way, so ask first.
 */
function nibwp_events_table_exists(string $table): bool
{
    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
        return false;
    }

    try {
        // The table name is built from $wpdb->prefix and a literal, never from
        // input — but it is still a VALUE in this statement, so it is prepared.
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    } catch (\Throwable $error) {
        return false;
    }

    return is_string($found) && $found !== '';
}

/** The ORM, or a refusal that says why the legacy writer is not used instead. */
function nibwp_events_orm(string $which): array|WP_Error
{
    $factories = [
        'events' => 'tribe_events',
        'venues' => 'tribe_venues',
        'organizers' => 'tribe_organizers',
    ];

    // An unknown kind is treated exactly like a missing factory rather than
    // falling through to whichever repository happens to be last in the list.
    $factory = $factories[$which] ?? $which;

    if (!isset($factories[$which]) || !function_exists($factory)) {
        return nibwp_events_err(
            'orm_missing',
            sprintf(
                /* translators: %s: the plugin function that is missing */
                __('%s() is not available, so this write cannot be made safely. The older tribe_create_event()/tribe_update_event() path is deliberately not used as a fallback: it reinterprets dates through the site datepicker setting and skips the UTC and duration meta entirely, which produces an event that exists but never appears on the calendar.', 'nibwp'),
                $factory
            ),
            501
        );
    }

    try {
        $repository = call_user_func($factory);
    } catch (\Throwable $error) {
        return nibwp_events_err('plugin_error', $error->getMessage(), 500);
    }

    if (!is_object($repository)) {
        return nibwp_events_err('orm_missing', __('The plugin returned no repository to write through.', 'nibwp'), 501);
    }

    return ['repository' => $repository];
}

/** Create a post through the ORM and return its id. */
function nibwp_events_orm_create(string $which, array $args): array|WP_Error
{
    $orm = nibwp_events_orm($which);

    if ($orm instanceof WP_Error) {
        return $orm;
    }

    try {
        $created = $orm['repository']->set_args($args)->create();
    } catch (\Throwable $error) {
        return nibwp_events_err('plugin_error', $error->getMessage(), 500);
    }

    if (is_wp_error($created)) {
        return $created;
    }

    $id = 0;

    if ($created instanceof WP_Post) {
        $id = (int) $created->ID;
    } elseif (is_numeric($created)) {
        $id = (int) $created;
    }

    if ($id <= 0) {
        return nibwp_events_err('write_failed', __('The plugin refused the create and gave no reason.', 'nibwp'), 500);
    }

    return ['id' => $id];
}

/** Save changes to one existing post through the ORM. */
function nibwp_events_orm_save(string $which, int $id, array $args): array|WP_Error
{
    $orm = nibwp_events_orm($which);

    if ($orm instanceof WP_Error) {
        return $orm;
    }

    try {
        // `save()` works on whatever the repository selected, so the selection has
        // to be this one id — and `post_status => any`, or a draft event selects
        // nothing and the save silently does nothing at all.
        $saved = $orm['repository']
            ->where('id', $id)
            ->where('post_status', 'any')
            ->set_args($args)
            ->save();
    } catch (\Throwable $error) {
        return nibwp_events_err('plugin_error', $error->getMessage(), 500);
    }

    if (is_wp_error($saved)) {
        return $saved;
    }

    if (is_array($saved) && $saved === []) {
        return nibwp_events_err('write_failed', __('The plugin selected no post to save, so nothing was changed.', 'nibwp'), 500);
    }

    return ['id' => $id];
}

/** Post content, through the house helper when it is loaded. */
function nibwp_events_content(mixed $raw): string
{
    $text = is_scalar($raw) ? (string) $raw : '';

    if (function_exists('nibwp_wp_prepare_post_content')) {
        return (string) nibwp_wp_prepare_post_content($text);
    }

    return $text;
}

/**
 * Turn a caller's event payload into ORM arguments.
 *
 * Raw `_Event*` keys rather than the ORM's friendly aliases, on purpose: the
 * repository routes any key that is not a post field or taxonomy straight into
 * `meta_input` (common/src/Tribe/Repository.php:3029-3031), and `update_date_meta()`
 * reads exactly these names (src/Tribe/Repositories/Event.php:1176). So what this
 * function builds is what lands in the database, and the check can assert the
 * key names.
 */
function nibwp_events_event_args(array $data, ?int $event_id): array|WP_Error
{
    $args = [];

    if (array_key_exists('title', $data)) {
        $title = is_scalar($data['title']) ? trim((string) $data['title']) : '';

        if ($title === '' && $event_id === null) {
            return nibwp_events_err('bad_request', __('title is required to create an event.', 'nibwp'));
        }

        if ($title !== '') {
            $args['post_title'] = sanitize_text_field($title);
        }
    } elseif ($event_id === null) {
        return nibwp_events_err('bad_request', __('title is required to create an event.', 'nibwp'));
    }

    if (array_key_exists('description', $data)) {
        $args['post_content'] = nibwp_events_content($data['description']);
    }

    if (array_key_exists('excerpt', $data)) {
        $args['post_excerpt'] = sanitize_text_field(is_scalar($data['excerpt']) ? (string) $data['excerpt'] : '');
    }

    if (array_key_exists('status', $data)) {
        $status = nibwp_events_statuses($data['status']);
        $args['post_status'] = $status[0];
    } elseif ($event_id === null) {
        // A new event an agent made starts as a draft. Publishing is a separate,
        // named action, so nothing an agent misunderstood goes live unannounced.
        $args['post_status'] = 'draft';
    }

    $dates = nibwp_events_build_date_meta($data, $event_id);

    if ($dates instanceof WP_Error) {
        return $dates;
    }

    foreach ($dates as $key => $value) {
        $args[$key] = $value;
    }

    if (array_key_exists('venue_id', $data)) {
        $venue_id = (int) $data['venue_id'];

        if ($venue_id > 0) {
            $check = nibwp_events_resolve($venue_id, 'venue');

            if ($check instanceof WP_Error) {
                return $check;
            }
        }

        $args['_EventVenueID'] = $venue_id;
    }

    if (array_key_exists('organizer_id', $data)) {
        $organizer_id = (int) $data['organizer_id'];

        if ($organizer_id > 0) {
            $check = nibwp_events_resolve($organizer_id, 'organizer');

            if ($check instanceof WP_Error) {
                return $check;
            }
        }

        $args['_EventOrganizerID'] = $organizer_id;
    }

    if (array_key_exists('cost', $data)) {
        $args['_EventCost'] = sanitize_text_field(is_scalar($data['cost']) ? (string) $data['cost'] : '');
    }

    if (array_key_exists('currency_symbol', $data)) {
        $args['_EventCurrencySymbol'] = sanitize_text_field(is_scalar($data['currency_symbol']) ? (string) $data['currency_symbol'] : '');
    }

    if (array_key_exists('website', $data)) {
        $args['_EventURL'] = esc_url_raw(is_scalar($data['website']) ? (string) $data['website'] : '');
    }

    if (array_key_exists('url', $data)) {
        // The old schema called it `url`; keeping it means an existing caller
        // still works after this rewrite.
        $args['_EventURL'] = esc_url_raw(is_scalar($data['url']) ? (string) $data['url'] : '');
    }

    if (array_key_exists('phone', $data)) {
        $args['_EventPhone'] = sanitize_text_field(is_scalar($data['phone']) ? (string) $data['phone'] : '');
    }

    if (array_key_exists('featured', $data)) {
        $args['_tribe_featured'] = nibwp_events_truthy($data['featured']) ? '1' : '';
    }

    if (array_key_exists('hide_from_upcoming', $data)) {
        $args['_EventHideFromUpcoming'] = nibwp_events_truthy($data['hide_from_upcoming']) ? 'yes' : '';
    }

    return $args;
}

/* ───────────────────────────── the actions ───────────────────────────────── */

/** Every action this hub offers, in the order the instructions describe them. */
function nibwp_events_actions(): array
{
    return [
        'list_events',
        'get_event',
        'create_event',
        'update_event',
        'set_event_status',
        'trash_event',
        'delete_event_permanently',
        'list_occurrences',
        'list_venues',
        'get_venue',
        'create_venue',
        'update_venue',
        'venue_events',
        'list_organizers',
        'get_organizer',
        'create_organizer',
        'update_organizer',
        'organizer_events',
        'list_event_categories',
        'list_event_tags',
        'set_event_terms',
        'calendar_between',
        'upcoming_events',
        'audit_calendar',
    ];
}

/** The seven names this hub has always answered to. */
function nibwp_events_legacy_actions(): array
{
    return ['list_events', 'get_event', 'create_event', 'update_event', 'list_venues', 'create_venue', 'list_organizers'];
}

/** A date range for a listing, either end optional. */
function nibwp_events_range(array $input): array|WP_Error
{
    $zone = nibwp_events_timezone($input['timezone'] ?? '', null);

    if ($zone instanceof WP_Error) {
        return $zone;
    }

    $from = null;
    $to = null;

    if (isset($input['from_date']) && '' !== $input['from_date']) {
        $parsed = nibwp_events_parse_datetime($input['from_date'], 'from_date', $zone['tz']);

        if ($parsed instanceof WP_Error) {
            return $parsed;
        }

        $from = $parsed;
    }

    if (isset($input['to_date']) && '' !== $input['to_date']) {
        $parsed = nibwp_events_parse_datetime($input['to_date'], 'to_date', $zone['tz']);

        if ($parsed instanceof WP_Error) {
            return $parsed;
        }

        // A bare date as the far end of a range means the whole of that day.
        $to = $parsed['date_only'] ? nibwp_events_day_boundary($parsed, $zone['tz'], 'end') : $parsed;
    }

    if ($from !== null && $to !== null && $to['object']->getTimestamp() < $from['object']->getTimestamp()) {
        return nibwp_events_err(
            'end_before_start',
            sprintf(
                /* translators: 1: to_date, 2: from_date */
                __('to_date %1$s is before from_date %2$s, which can only ever match nothing.', 'nibwp'),
                $to['local'],
                $from['local']
            )
        );
    }

    return ['from' => $from, 'to' => $to, 'timezone' => $zone['name']];
}

/**
 * The meta query for a range.
 *
 * An event overlaps the window when it starts before the window ends and ends
 * after the window starts — the same test the repository's `date_overlaps` filter
 * makes (src/Tribe/Repositories/Event.php:463).
 */
function nibwp_events_range_meta_query(array $range): array
{
    $start_key = nibwp_events_start_key();
    $end_key = nibwp_events_end_key();
    $utc = '_EventStartDateUTC' === $start_key;
    $meta = ['relation' => 'AND'];

    if ($range['from'] !== null) {
        $value = $utc
            ? $range['from']['object']->setTimezone(new DateTimeZone('UTC'))->format(NIBWP_EVENTS_DB_FORMAT)
            : $range['from']['local'];
        $meta[] = ['key' => $end_key, 'value' => $value, 'compare' => '>=', 'type' => 'DATETIME'];
    }

    if ($range['to'] !== null) {
        $value = $utc
            ? $range['to']['object']->setTimezone(new DateTimeZone('UTC'))->format(NIBWP_EVENTS_DB_FORMAT)
            : $range['to']['local'];
        $meta[] = ['key' => $start_key, 'value' => $value, 'compare' => '<=', 'type' => 'DATETIME'];
    }

    return count($meta) > 1 ? $meta : [];
}

/** list_events — a real date range, a status filter, and a bound. */
function nibwp_events_list_events(array $input): array|WP_Error
{
    $denied = nibwp_events_require(__('Listing events', 'nibwp'));

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $range = nibwp_events_range($input);

    if ($range instanceof WP_Error) {
        return $range;
    }

    $per_page = nibwp_events_clamp($input['per_page'] ?? null, NIBWP_EVENTS_DEFAULT_ROWS, NIBWP_EVENTS_MAX_ROWS);
    $page = nibwp_events_clamp($input['page'] ?? null, 1, 1000);
    $statuses = nibwp_events_statuses($input['status'] ?? null);

    $args = [
        'post_type' => nibwp_events_type('event'),
        'post_status' => $statuses,
        'posts_per_page' => $per_page,
        'paged' => $page,
        'orderby' => 'meta_value',
        'meta_key' => nibwp_events_start_key(),
        'order' => 'ASC',
    ];

    $meta_query = nibwp_events_range_meta_query($range);

    if ($meta_query !== []) {
        $args['meta_query'] = $meta_query;
    }

    if (isset($input['venue_id']) && (int) $input['venue_id'] > 0) {
        $args['meta_query'][] = ['key' => '_EventVenueID', 'value' => (int) $input['venue_id']];
    }

    if (isset($input['organizer_id']) && (int) $input['organizer_id'] > 0) {
        $args['meta_query'][] = ['key' => '_EventOrganizerID', 'value' => (int) $input['organizer_id']];
    }

    $found = nibwp_events_query_posts($args);
    $events = [];

    foreach ($found['items'] as $post) {
        $events[] = nibwp_events_event_row($post);
    }

    return [
        'events' => $events,
        'total' => count($events),
        'skipped' => $found['skipped'],
        'skipped_note' => nibwp_events_skipped_note($found['skipped']),
        'per_page' => $per_page,
        'page' => $page,
        'max_per_page' => NIBWP_EVENTS_MAX_ROWS,
        'statuses' => $statuses,
        'timezone' => $range['timezone'],
        'sorted_on' => nibwp_events_start_key(),
    ];
}

/** get_event — the whole record, venue and organizer included. */
function nibwp_events_get_event(array $input): array|WP_Error
{
    $id = (int) ($input['event_id'] ?? 0);
    $resolved = nibwp_events_resolve($id, 'event');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    if (!nibwp_events_can_edit($id)) {
        return nibwp_events_forbidden(__('Reading this event', 'nibwp'), 'edit_post');
    }

    return ['event' => nibwp_events_event_detail($resolved['post'])];
}

/** create_event — through the ORM, with the whole date set written. */
function nibwp_events_create_event(array $input): array|WP_Error
{
    $denied = nibwp_events_require(__('Creating an event', 'nibwp'));

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $data = is_array($input['event_data'] ?? null) ? $input['event_data'] : [];
    $args = nibwp_events_event_args($data, null);

    if ($args instanceof WP_Error) {
        return $args;
    }

    $created = nibwp_events_orm_create('events', $args);

    if ($created instanceof WP_Error) {
        return $created;
    }

    $post = get_post($created['id']);

    return [
        'event' => $post instanceof WP_Post
            ? nibwp_events_event_detail($post)
            : ['id' => $created['id'], 'title' => (string) ($args['post_title'] ?? '')],
        'created' => true,
        'date_meta_written' => nibwp_events_required_date_meta(),
    ];
}

/** update_event — same date discipline, on an id that was type-checked first. */
function nibwp_events_update_event(array $input): array|WP_Error
{
    $id = (int) ($input['event_id'] ?? 0);
    $resolved = nibwp_events_resolve($id, 'event');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    if (!nibwp_events_can_edit($id)) {
        return nibwp_events_forbidden(__('Updating this event', 'nibwp'), 'edit_post');
    }

    $data = is_array($input['event_data'] ?? null) ? $input['event_data'] : [];

    if ($data === []) {
        return nibwp_events_err('bad_request', __('event_data is empty, so there is nothing to change.', 'nibwp'));
    }

    $args = nibwp_events_event_args($data, $id);

    if ($args instanceof WP_Error) {
        return $args;
    }

    $saved = nibwp_events_orm_save('events', $id, $args);

    if ($saved instanceof WP_Error) {
        return $saved;
    }

    $post = get_post($id);

    return [
        'event' => $post instanceof WP_Post ? nibwp_events_event_detail($post) : ['id' => $id],
        'updated' => true,
        'fields_changed' => array_values(array_keys($args)),
    ];
}

/** set_event_status — publishing is its own decision, so it is its own action. */
function nibwp_events_set_event_status(array $input): array|WP_Error
{
    $id = (int) ($input['event_id'] ?? 0);
    $resolved = nibwp_events_resolve($id, 'event');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    if (!nibwp_events_can_edit($id)) {
        return nibwp_events_forbidden(__('Changing this event status', 'nibwp'), 'edit_post');
    }

    $wanted = is_string($input['status'] ?? null) ? strtolower(trim((string) $input['status'])) : '';
    $allowed = ['publish', 'draft', 'pending', 'private', 'future'];

    if (!in_array($wanted, $allowed, true)) {
        return nibwp_events_err(
            'bad_request',
            sprintf(
                /* translators: %s: comma separated list of statuses */
                __('status must be one of: %s. Trashing and deleting have their own actions.', 'nibwp'),
                implode(', ', $allowed)
            )
        );
    }

    // A published event with no dates is the failure this file exists to prevent,
    // so publishing checks the meta is actually there first.
    if ('publish' === $wanted) {
        $missing = nibwp_events_missing_date_meta($id);

        if ($missing !== []) {
            return nibwp_events_err(
                'incomplete_dates',
                sprintf(
                    /* translators: 1: post ID, 2: comma separated meta keys */
                    __('Event %1$d is missing %2$s, so publishing it would put an event on the site that the calendar cannot show. Set its dates first with update_event.', 'nibwp'),
                    $id,
                    implode(', ', $missing)
                ),
                409
            );
        }
    }

    $saved = nibwp_events_orm_save('events', $id, ['post_status' => $wanted]);

    if ($saved instanceof WP_Error) {
        return $saved;
    }

    return ['event' => ['id' => $id, 'status' => $wanted], 'updated' => true];
}

/** Which of the mandatory date keys is this event missing? */
function nibwp_events_missing_date_meta(int $id): array
{
    $missing = [];

    foreach (nibwp_events_required_date_meta() as $key) {
        $value = get_post_meta($id, $key, true);

        if ($value === '' || $value === false || $value === null) {
            $missing[] = $key;
        }
    }

    return $missing;
}

/** trash_event — the reversible one, and the one to prefer. */
function nibwp_events_trash_event(array $input): array|WP_Error
{
    $id = (int) ($input['event_id'] ?? 0);
    $resolved = nibwp_events_resolve($id, 'event');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    if (!nibwp_events_can_delete($id)) {
        return nibwp_events_forbidden(__('Trashing this event', 'nibwp'), 'delete_post');
    }

    try {
        $trashed = wp_trash_post($id);
    } catch (\Throwable $error) {
        return nibwp_events_err('plugin_error', $error->getMessage(), 500);
    }

    if (!$trashed) {
        return nibwp_events_err('write_failed', __('WordPress did not trash the event.', 'nibwp'), 500);
    }

    return [
        'event' => ['id' => $id, 'title' => (string) $resolved['post']->post_title],
        'trashed' => true,
        'recoverable' => true,
        'note' => __('The event is in the trash and can be restored from wp-admin.', 'nibwp'),
    ];
}

/**
 * delete_event_permanently — IRREVERSIBLE, which is why it says so in its name.
 *
 * An event carries attendee and ticket relationships that other add-ons own.
 * Deleting the post bypasses the trash and there is no undo, so the action is
 * named for what it does and asks for `delete_post` on this one id.
 */
function nibwp_events_delete_event_permanently(array $input): array|WP_Error
{
    $id = (int) ($input['event_id'] ?? 0);
    $resolved = nibwp_events_resolve($id, 'event');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    if (!nibwp_events_can_delete($id)) {
        return nibwp_events_forbidden(__('Permanently deleting this event', 'nibwp'), 'delete_post');
    }

    $title = (string) $resolved['post']->post_title;

    try {
        $deleted = wp_delete_post($id, true);
    } catch (\Throwable $error) {
        return nibwp_events_err('plugin_error', $error->getMessage(), 500);
    }

    if (!$deleted) {
        return nibwp_events_err('write_failed', __('WordPress did not delete the event.', 'nibwp'), 500);
    }

    return [
        'event' => ['id' => $id, 'title' => $title],
        'deleted' => true,
        'recoverable' => false,
        'note' => __('This was permanent. The event, its meta and its links to venues, organizers and any ticket records are gone and cannot be restored.', 'nibwp'),
    ];
}

/**
 * list_occurrences — what the calendar will actually draw for this event.
 *
 * Every event has at least one row in `tec_occurrences`, including a single
 * event, so this read is useful on a plain install and not only where recurrence
 * is available. A recurrence RULE, on the other hand, is written by the premium
 * add-on: core only ever reads `_EventRecurrence`
 * (src/Events/Custom_Tables/V1/Migration/Strategies/Single_Event_Migration_Strategy.php:57
 * and src/views/single-event/recurring-description.php:16) and the series post
 * type is registered elsewhere, so this action reports whether a rule and a
 * series exist rather than pretending it can edit them.
 */
function nibwp_events_list_occurrences(array $input): array|WP_Error
{
    global $wpdb;

    $id = (int) ($input['event_id'] ?? 0);
    $resolved = nibwp_events_resolve($id, 'event');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    if (!nibwp_events_can_edit($id)) {
        return nibwp_events_forbidden(__('Reading this event occurrences', 'nibwp'), 'edit_post');
    }

    $limit = nibwp_events_clamp($input['per_page'] ?? null, NIBWP_EVENTS_DEFAULT_ROWS, NIBWP_EVENTS_MAX_ROWS);
    $table = $wpdb->prefix . 'tec_occurrences';
    $occurrences = [];
    $available = nibwp_events_table_exists($table);

    if ($available) {
        try {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT occurrence_id, start_date, end_date, start_date_utc, end_date_utc, duration
                     FROM {$table}
                     WHERE post_id = %d
                     ORDER BY start_date ASC
                     LIMIT %d",
                    $id,
                    $limit
                ),
                ARRAY_A
            );
        } catch (\Throwable $error) {
            return nibwp_events_err('plugin_error', $error->getMessage(), 500);
        }

        foreach (is_array($rows) ? $rows : [] as $row) {
            $occurrences[] = [
                'occurrence_id' => (int) ($row['occurrence_id'] ?? 0),
                'start_date' => (string) ($row['start_date'] ?? ''),
                'end_date' => (string) ($row['end_date'] ?? ''),
                'start_date_utc' => (string) ($row['start_date_utc'] ?? ''),
                'end_date_utc' => (string) ($row['end_date_utc'] ?? ''),
                'duration_seconds' => (int) ($row['duration'] ?? 0),
            ];
        }
    }

    $rule = get_post_meta($id, '_EventRecurrence', true);
    $series = null;

    if (function_exists('tec_event_series')) {
        try {
            $series_post = tec_event_series($id);

            if ($series_post instanceof WP_Post) {
                $series = ['id' => (int) $series_post->ID, 'title' => (string) $series_post->post_title];
            }
        } catch (\Throwable $error) {
            $series = null;
        }
    }

    return [
        'event' => ['id' => $id, 'title' => (string) $resolved['post']->post_title],
        'occurrences' => $occurrences,
        'total' => count($occurrences),
        'per_page' => $limit,
        'max_per_page' => NIBWP_EVENTS_MAX_ROWS,
        'occurrences_table_available' => $available,
        'has_recurrence_rule' => !($rule === '' || $rule === false || $rule === null),
        'series' => $series,
        'recurrence_editing_available' => false,
        'note' => __('Occurrences are readable here. Recurrence rules and series are written by the premium calendar add-on, not by this plugin, so they are reported and not edited.', 'nibwp'),
    ];
}

/* ─────────────────────────────── venues ──────────────────────────────────── */

/** A listing of venues or organizers, bounded and filtered. */
function nibwp_events_list_linked(string $kind, array $input): array|WP_Error
{
    $denied = nibwp_events_require(
        'venue' === $kind ? __('Listing venues', 'nibwp') : __('Listing organizers', 'nibwp')
    );

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $per_page = nibwp_events_clamp($input['per_page'] ?? null, NIBWP_EVENTS_DEFAULT_ROWS, NIBWP_EVENTS_MAX_ROWS);
    $page = nibwp_events_clamp($input['page'] ?? null, 1, 1000);
    $allow = 'venue' === $kind ? nibwp_events_public_venue_meta() : nibwp_events_public_organizer_meta();

    $args = [
        'post_type' => nibwp_events_type($kind),
        'post_status' => nibwp_events_statuses($input['status'] ?? ['publish', 'draft', 'private']),
        'posts_per_page' => $per_page,
        'paged' => $page,
        'orderby' => 'title',
        'order' => 'ASC',
    ];

    if (isset($input['search']) && is_string($input['search']) && '' !== trim($input['search'])) {
        $args['s'] = sanitize_text_field($input['search']);
    }

    $found = nibwp_events_query_posts($args);
    $rows = [];

    foreach ($found['items'] as $post) {
        $meta = nibwp_events_read_meta((int) $post->ID, $allow, nibwp_events_linked_field_map($kind));
        $rows[] = [
            'id' => (int) $post->ID,
            'name' => (string) $post->post_title,
            'status' => (string) $post->post_status,
            'event_count' => nibwp_events_linked_event_count((int) $post->ID, $kind),
        ] + $meta['values'];
    }

    $key = 'venue' === $kind ? 'venues' : 'organizers';

    return [
        $key => $rows,
        'total' => count($rows),
        'skipped' => $found['skipped'],
        'skipped_note' => nibwp_events_skipped_note($found['skipped']),
        'per_page' => $per_page,
        'page' => $page,
        'max_per_page' => NIBWP_EVENTS_MAX_ROWS,
    ];
}

/** How many events point at this venue or organizer? */
function nibwp_events_linked_event_count(int $id, string $kind): int
{
    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
        return 0;
    }

    $meta_key = 'venue' === $kind ? '_EventVenueID' : '_EventOrganizerID';

    try {
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
                 WHERE p.post_type = %s
                   AND p.post_status NOT IN ('trash', 'auto-draft')
                   AND m.meta_key = %s
                   AND m.meta_value = %s",
                nibwp_events_type('event'),
                $meta_key,
                (string) $id
            )
        );
    } catch (\Throwable $error) {
        return 0;
    }

    return (int) $count;
}

/** get_venue / get_organizer. */
function nibwp_events_get_linked(string $kind, array $input): array|WP_Error
{
    $field = 'venue' === $kind ? 'venue_id' : 'organizer_id';
    $id = (int) ($input[$field] ?? 0);
    $resolved = nibwp_events_resolve($id, $kind);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    if (!nibwp_events_can_edit($id)) {
        return nibwp_events_forbidden(
            'venue' === $kind ? __('Reading this venue', 'nibwp') : __('Reading this organizer', 'nibwp'),
            'edit_post'
        );
    }

    $allow = 'venue' === $kind ? nibwp_events_public_venue_meta() : nibwp_events_public_organizer_meta();
    $meta = nibwp_events_read_meta($id, $allow, nibwp_events_linked_field_map($kind));

    $record = [
        'id' => $id,
        'name' => (string) $resolved['post']->post_title,
        'status' => (string) $resolved['post']->post_status,
        'description' => (string) $resolved['post']->post_content,
        'event_count' => nibwp_events_linked_event_count($id, $kind),
        'withheld_meta' => $meta['withheld'],
    ] + $meta['values'];

    $key = 'venue' === $kind ? 'venue' : 'organizer';

    return [$key => $record];
}

/** The ORM arguments for a venue or organizer write. */
function nibwp_events_linked_args(string $kind, array $data, bool $is_create): array|WP_Error
{
    $map = 'venue' === $kind
        ? [
            'venue' => 'post_title',
            'name' => 'post_title',
            'address' => '_VenueAddress',
            'city' => '_VenueCity',
            'state' => '_VenueState',
            'province' => '_VenueProvince',
            'country' => '_VenueCountry',
            'zip' => '_VenueZip',
            'phone' => '_VenuePhone',
            'website' => '_VenueURL',
        ]
        : [
            'organizer' => 'post_title',
            'name' => 'post_title',
            'phone' => '_OrganizerPhone',
            'website' => '_OrganizerWebsite',
            'email' => '_OrganizerEmail',
        ];

    $args = [];

    foreach ($map as $from => $to) {
        if (!array_key_exists($from, $data)) {
            continue;
        }

        $raw = is_scalar($data[$from]) ? (string) $data[$from] : '';

        if ('_VenueURL' === $to || '_OrganizerWebsite' === $to) {
            $args[$to] = esc_url_raw($raw);
            continue;
        }

        if ('_OrganizerEmail' === $to) {
            $email = sanitize_email($raw);

            if ($raw !== '' && $email === '') {
                return nibwp_events_err('bad_request', __('email is not a usable address.', 'nibwp'));
            }

            $args[$to] = $email;
            continue;
        }

        $args[$to] = sanitize_text_field($raw);
    }

    // The plugin keeps a combined state/province key alongside the two separate
    // ones and its own templates read the combined one, so a write that sets only
    // `state` would show an address with no region (src/Tribe/Repositories/Venue.php:48-51).
    if (isset($args['_VenueState']) || isset($args['_VenueProvince'])) {
        $args['_VenueStateProvince'] = (string) ($args['_VenueState'] ?? $args['_VenueProvince'] ?? '');
    }

    if (array_key_exists('description', $data)) {
        $args['post_content'] = nibwp_events_content($data['description']);
    }

    if (array_key_exists('status', $data)) {
        $statuses = nibwp_events_statuses($data['status']);
        $args['post_status'] = $statuses[0];
    } elseif ($is_create) {
        $args['post_status'] = 'publish';
    }

    if ($is_create && ($args['post_title'] ?? '') === '') {
        return nibwp_events_err(
            'bad_request',
            'venue' === $kind
                ? __('venue_data.venue (the name) is required.', 'nibwp')
                : __('organizer_data.organizer (the name) is required.', 'nibwp')
        );
    }

    if ($args === []) {
        return nibwp_events_err('bad_request', __('Nothing in that payload can be written.', 'nibwp'));
    }

    return $args;
}

/** create_venue / create_organizer. */
function nibwp_events_create_linked(string $kind, array $input): array|WP_Error
{
    $denied = nibwp_events_require(
        'venue' === $kind ? __('Creating a venue', 'nibwp') : __('Creating an organizer', 'nibwp')
    );

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $field = 'venue' === $kind ? 'venue_data' : 'organizer_data';
    $data = is_array($input[$field] ?? null) ? $input[$field] : [];
    $args = nibwp_events_linked_args($kind, $data, true);

    if ($args instanceof WP_Error) {
        return $args;
    }

    $created = nibwp_events_orm_create('venue' === $kind ? 'venues' : 'organizers', $args);

    if ($created instanceof WP_Error) {
        return $created;
    }

    $key = 'venue' === $kind ? 'venue' : 'organizer';

    return [
        $key => ['id' => $created['id'], 'name' => (string) ($args['post_title'] ?? '')],
        'created' => true,
    ];
}

/** update_venue / update_organizer. */
function nibwp_events_update_linked(string $kind, array $input): array|WP_Error
{
    $id_field = 'venue' === $kind ? 'venue_id' : 'organizer_id';
    $id = (int) ($input[$id_field] ?? 0);
    $resolved = nibwp_events_resolve($id, $kind);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    if (!nibwp_events_can_edit($id)) {
        return nibwp_events_forbidden(
            'venue' === $kind ? __('Updating this venue', 'nibwp') : __('Updating this organizer', 'nibwp'),
            'edit_post'
        );
    }

    $data_field = 'venue' === $kind ? 'venue_data' : 'organizer_data';
    $data = is_array($input[$data_field] ?? null) ? $input[$data_field] : [];
    $args = nibwp_events_linked_args($kind, $data, false);

    if ($args instanceof WP_Error) {
        return $args;
    }

    $saved = nibwp_events_orm_save('venue' === $kind ? 'venues' : 'organizers', $id, $args);

    if ($saved instanceof WP_Error) {
        return $saved;
    }

    $key = 'venue' === $kind ? 'venue' : 'organizer';

    return [
        $key => ['id' => $id, 'name' => (string) ($args['post_title'] ?? $resolved['post']->post_title)],
        'updated' => true,
        'fields_changed' => array_values(array_keys($args)),
    ];
}

/** venue_events / organizer_events — which events use this one. */
function nibwp_events_linked_events(string $kind, array $input): array|WP_Error
{
    $id_field = 'venue' === $kind ? 'venue_id' : 'organizer_id';
    $id = (int) ($input[$id_field] ?? 0);
    $resolved = nibwp_events_resolve($id, $kind);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    if (!nibwp_events_can_edit($id)) {
        return nibwp_events_forbidden(
            'venue' === $kind ? __('Reading this venue events', 'nibwp') : __('Reading this organizer events', 'nibwp'),
            'edit_post'
        );
    }

    $per_page = nibwp_events_clamp($input['per_page'] ?? null, NIBWP_EVENTS_DEFAULT_ROWS, NIBWP_EVENTS_MAX_ROWS);
    $page = nibwp_events_clamp($input['page'] ?? null, 1, 1000);

    $found = nibwp_events_query_posts([
        'post_type' => nibwp_events_type('event'),
        'post_status' => nibwp_events_statuses($input['status'] ?? 'any'),
        'posts_per_page' => $per_page,
        'paged' => $page,
        'orderby' => 'meta_value',
        'meta_key' => nibwp_events_start_key(),
        'order' => 'ASC',
        'meta_query' => [
            ['key' => 'venue' === $kind ? '_EventVenueID' : '_EventOrganizerID', 'value' => (string) $id],
        ],
    ]);

    $events = [];

    foreach ($found['items'] as $post) {
        $events[] = nibwp_events_event_row($post);
    }

    $key = 'venue' === $kind ? 'venue' : 'organizer';

    return [
        $key => ['id' => $id, 'name' => (string) $resolved['post']->post_title],
        'events' => $events,
        'total' => count($events),
        'skipped' => $found['skipped'],
        'skipped_note' => nibwp_events_skipped_note($found['skipped']),
        'per_page' => $per_page,
        'page' => $page,
        'max_per_page' => NIBWP_EVENTS_MAX_ROWS,
    ];
}

/* ──────────────────────── categories and tags ────────────────────────────── */

/** list_event_categories / list_event_tags. */
function nibwp_events_list_terms(string $taxonomy, array $input): array|WP_Error
{
    $denied = nibwp_events_require(__('Listing event terms', 'nibwp'));

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    if ($taxonomy === '') {
        return nibwp_events_err('plugin_error', __('The plugin did not name its event taxonomy.', 'nibwp'), 500);
    }

    $limit = nibwp_events_clamp($input['per_page'] ?? null, 100, NIBWP_EVENTS_MAX_ROWS);

    try {
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'number' => $limit,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);
    } catch (\Throwable $error) {
        return nibwp_events_err('plugin_error', $error->getMessage(), 500);
    }

    if (is_wp_error($terms)) {
        return $terms;
    }

    $rows = [];

    foreach (is_array($terms) ? $terms : [] as $term) {
        if (!is_object($term)) {
            continue;
        }

        $rows[] = [
            'id' => (int) ($term->term_id ?? 0),
            'name' => (string) ($term->name ?? ''),
            'slug' => (string) ($term->slug ?? ''),
            'parent' => (int) ($term->parent ?? 0),
            'count' => (int) ($term->count ?? 0),
        ];
    }

    return [
        'taxonomy' => $taxonomy,
        'terms' => $rows,
        'total' => count($rows),
        'per_page' => $limit,
        'max_per_page' => NIBWP_EVENTS_MAX_ROWS,
    ];
}

/** set_event_terms — categories and tags on one event. */
function nibwp_events_set_event_terms(array $input): array|WP_Error
{
    $id = (int) ($input['event_id'] ?? 0);
    $resolved = nibwp_events_resolve($id, 'event');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    if (!nibwp_events_can_edit($id)) {
        return nibwp_events_forbidden(__('Changing the terms on this event', 'nibwp'), 'edit_post');
    }

    $categories = $input['categories'] ?? null;
    $tags = $input['tags'] ?? null;

    if ($categories === null && $tags === null) {
        return nibwp_events_err('bad_request', __('Send categories, tags, or both. An empty array clears that taxonomy.', 'nibwp'));
    }

    $append = nibwp_events_truthy($input['append'] ?? false);
    $changed = [];

    try {
        if ($categories !== null) {
            $result = wp_set_object_terms($id, nibwp_events_term_list($categories), nibwp_events_taxonomy(), $append);

            if (is_wp_error($result)) {
                return $result;
            }

            $changed[] = nibwp_events_taxonomy();
        }

        if ($tags !== null) {
            $result = wp_set_object_terms($id, nibwp_events_term_list($tags), 'post_tag', $append);

            if (is_wp_error($result)) {
                return $result;
            }

            $changed[] = 'post_tag';
        }
    } catch (\Throwable $error) {
        return nibwp_events_err('plugin_error', $error->getMessage(), 500);
    }

    return [
        'event' => [
            'id' => $id,
            'categories' => nibwp_events_terms_of($id, nibwp_events_taxonomy()),
            'tags' => nibwp_events_terms_of($id, 'post_tag'),
        ],
        'updated' => true,
        'taxonomies_changed' => $changed,
        'appended' => $append,
    ];
}

/** Terms as ids where they are numeric, names otherwise. */
function nibwp_events_term_list(mixed $raw): array
{
    $list = is_array($raw) ? $raw : (is_scalar($raw) && '' !== $raw ? [$raw] : []);
    $out = [];

    foreach ($list as $item) {
        if (is_numeric($item)) {
            $out[] = (int) $item;
            continue;
        }

        if (is_string($item) && '' !== trim($item)) {
            $out[] = sanitize_text_field($item);
        }
    }

    return $out;
}

/* ────────────────────────── calendar-shaped reads ────────────────────────── */

/** calendar_between — what is on between two dates. */
function nibwp_events_calendar_between(array $input): array|WP_Error
{
    $denied = nibwp_events_require(__('Reading the calendar', 'nibwp'));

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    if (!isset($input['from_date']) || !isset($input['to_date'])) {
        return nibwp_events_err('bad_request', __('calendar_between needs both from_date and to_date.', 'nibwp'));
    }

    $range = nibwp_events_range($input);

    if ($range instanceof WP_Error) {
        return $range;
    }

    $per_page = nibwp_events_clamp($input['per_page'] ?? null, NIBWP_EVENTS_MAX_ROWS, NIBWP_EVENTS_MAX_ROWS);

    $found = nibwp_events_query_posts([
        'post_type' => nibwp_events_type('event'),
        'post_status' => nibwp_events_statuses($input['status'] ?? null),
        'posts_per_page' => $per_page,
        'paged' => 1,
        'orderby' => 'meta_value',
        'meta_key' => nibwp_events_start_key(),
        'order' => 'ASC',
        'meta_query' => nibwp_events_range_meta_query($range),
    ]);

    $days = [];
    $events = [];

    foreach ($found['items'] as $post) {
        $row = nibwp_events_event_row($post);
        $events[] = $row;
        $day = substr($row['start_date'], 0, 10);

        if ($day === '') {
            continue;
        }

        if (!isset($days[$day])) {
            $days[$day] = [];
        }

        $days[$day][] = ['id' => $row['id'], 'title' => $row['title'], 'start_date' => $row['start_date']];
    }

    ksort($days);

    return [
        'from_date' => $range['from'] === null ? '' : $range['from']['local'],
        'to_date' => $range['to'] === null ? '' : $range['to']['local'],
        'timezone' => $range['timezone'],
        'by_day' => $days,
        'events' => $events,
        'total' => count($events),
        'skipped' => $found['skipped'],
        'skipped_note' => nibwp_events_skipped_note($found['skipped']),
        'per_page' => $per_page,
        'max_per_page' => NIBWP_EVENTS_MAX_ROWS,
    ];
}

/** upcoming_events — the next N, from now. */
function nibwp_events_upcoming(array $input): array|WP_Error
{
    $denied = nibwp_events_require(__('Reading upcoming events', 'nibwp'));

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $count = nibwp_events_clamp($input['count'] ?? ($input['per_page'] ?? null), NIBWP_EVENTS_DEFAULT_ROWS, NIBWP_EVENTS_MAX_ROWS);
    $now = nibwp_events_now();

    $found = nibwp_events_query_posts([
        'post_type' => nibwp_events_type('event'),
        'post_status' => nibwp_events_statuses($input['status'] ?? null),
        'posts_per_page' => $count,
        'paged' => 1,
        'orderby' => 'meta_value',
        'meta_key' => nibwp_events_start_key(),
        'order' => 'ASC',
        'meta_query' => [
            ['key' => nibwp_events_end_key(), 'value' => $now, 'compare' => '>=', 'type' => 'DATETIME'],
        ],
    ]);

    $events = [];

    foreach ($found['items'] as $post) {
        $events[] = nibwp_events_event_row($post);
    }

    return [
        'events' => $events,
        'total' => count($events),
        'count_requested' => $count,
        'max_per_page' => NIBWP_EVENTS_MAX_ROWS,
        'from' => $now,
        'compared_on' => nibwp_events_end_key(),
        'skipped' => $found['skipped'],
        'skipped_note' => nibwp_events_skipped_note($found['skipped']),
    ];
}

/* ──────────────────────────────── the audit ──────────────────────────────── */

/**
 * audit_calendar — the six things an owner would actually act on.
 *
 * Every query is prepared, bounded, and filtered by `edit_post` afterwards with a
 * count of what it dropped, so a partial audit never reads as a clean one.
 */
function nibwp_events_audit(array $input): array|WP_Error
{
    global $wpdb;

    $denied = nibwp_events_require(__('Auditing the calendar', 'nibwp'));

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
        return nibwp_events_err('plugin_error', __('No database handle is available.', 'nibwp'), 500);
    }

    $limit = nibwp_events_clamp($input['per_page'] ?? null, 50, NIBWP_EVENTS_MAX_ROWS);
    $event_type = nibwp_events_type('event');
    $venue_type = nibwp_events_type('venue');
    $organizer_type = nibwp_events_type('organizer');
    $now_utc = gmdate(NIBWP_EVENTS_DB_FORMAT);

    $findings = [];
    $skipped = 0;

    // 1. Published, on the site, with nowhere to go.
    $findings['published_without_venue'] = nibwp_events_audit_rows(
        $wpdb->prepare(
            "SELECT p.ID, p.post_title
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE p.post_type = %s
               AND p.post_status = %s
               AND (m.meta_value IS NULL OR m.meta_value = %s OR m.meta_value = %s)
             ORDER BY p.ID DESC
             LIMIT %d",
            '_EventVenueID',
            $event_type,
            'publish',
            '',
            '0',
            $limit
        ),
        __('Published events with no venue. Visitors see an event with no address.', 'nibwp'),
        $skipped
    );

    // 2. Ends before it starts. The plugin's writers would have corrected this, so
    // a row here came in another way — an import, a direct meta write, a migration.
    $findings['ends_before_start'] = nibwp_events_audit_rows(
        $wpdb->prepare(
            "SELECT p.ID, p.post_title, s.meta_value AS start_date, e.meta_value AS end_date
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
             INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s
             WHERE p.post_type = %s
               AND p.post_status NOT IN ('trash', 'auto-draft')
               AND e.meta_value < s.meta_value
             ORDER BY p.ID DESC
             LIMIT %d",
            '_EventStartDate',
            '_EventEndDate',
            $event_type,
            $limit
        ),
        __('Events whose end is before their start. These sort and display wrongly everywhere.', 'nibwp'),
        $skipped
    );

    // 3. Over, and still taking up the front page.
    $findings['past_but_featured_or_sticky'] = nibwp_events_audit_rows(
        $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.menu_order, e.meta_value AS end_date_utc, f.meta_value AS featured
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = %s
             WHERE p.post_type = %s
               AND p.post_status = %s
               AND e.meta_value < %s
               AND (p.menu_order = %d OR (f.meta_value IS NOT NULL AND f.meta_value <> %s))
             ORDER BY e.meta_value DESC
             LIMIT %d",
            '_EventEndDateUTC',
            '_tribe_featured',
            $event_type,
            'publish',
            $now_utc,
            -1,
            '',
            $limit
        ),
        __('Past events still featured or stuck to the top of the calendar.', 'nibwp'),
        $skipped
    );

    // 4 and 5. Records nothing points at. Not wrong, but worth a tidy, and a long
    // tail of them usually means an import went in twice.
    $findings['venues_with_no_events'] = nibwp_events_audit_rows(
        $wpdb->prepare(
            "SELECT p.ID, p.post_title
             FROM {$wpdb->posts} p
             WHERE p.post_type = %s
               AND p.post_status NOT IN ('trash', 'auto-draft')
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->postmeta} m
                   INNER JOIN {$wpdb->posts} e ON e.ID = m.post_id AND e.post_type = %s
                   WHERE m.meta_key = %s AND m.meta_value = CAST(p.ID AS CHAR)
               )
             ORDER BY p.ID DESC
             LIMIT %d",
            $venue_type,
            $event_type,
            '_EventVenueID',
            $limit
        ),
        __('Venues no event uses.', 'nibwp'),
        $skipped
    );

    $findings['organizers_with_no_events'] = nibwp_events_audit_rows(
        $wpdb->prepare(
            "SELECT p.ID, p.post_title
             FROM {$wpdb->posts} p
             WHERE p.post_type = %s
               AND p.post_status NOT IN ('trash', 'auto-draft')
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->postmeta} m
                   INNER JOIN {$wpdb->posts} e ON e.ID = m.post_id AND e.post_type = %s
                   WHERE m.meta_key = %s AND m.meta_value = CAST(p.ID AS CHAR)
               )
             ORDER BY p.ID DESC
             LIMIT %d",
            $organizer_type,
            $event_type,
            '_EventOrganizerID',
            $limit
        ),
        __('Organizers no event uses.', 'nibwp'),
        $skipped
    );

    // 6. The failure this whole file is arranged to prevent: a post that is an
    // event as far as wp-admin is concerned and has no date at all, so no view
    // will ever draw it.
    $findings['no_date_at_all'] = nibwp_events_audit_rows(
        $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_status
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = %s
             WHERE p.post_type = %s
               AND p.post_status NOT IN ('trash', 'auto-draft')
               AND (s.meta_value IS NULL OR s.meta_value = %s OR u.meta_value IS NULL OR u.meta_value = %s)
             ORDER BY p.ID DESC
             LIMIT %d",
            '_EventStartDate',
            '_EventStartDateUTC',
            $event_type,
            '',
            '',
            $limit
        ),
        __('Events with no start date, or with a local start and no UTC mirror. Neither appears on the calendar.', 'nibwp'),
        $skipped
    );

    $total = 0;
    $errors = [];

    foreach ($findings as $label => $finding) {
        $total += (int) $finding['count'];

        if (isset($finding['error'])) {
            $errors[] = $label;
        }
    }

    // A query that failed is not a finding of zero. Saying "clean" here would be
    // the one wrong answer an owner acts on.
    return [
        'findings' => $findings,
        'total_findings' => $total,
        'partial' => $errors !== [],
        'checks_that_failed' => $errors,
        'clean' => 0 === $total && $errors === [],
        'skipped' => $skipped,
        'skipped_note' => nibwp_events_skipped_note($skipped),
        'per_page' => $limit,
        'max_per_page' => NIBWP_EVENTS_MAX_ROWS,
        'required_date_meta' => nibwp_events_required_date_meta(),
        'checked_at_utc' => $now_utc,
    ];
}

/**
 * Run one prepared audit query and drop rows this account cannot edit.
 *
 * @param string $sql A statement already through $wpdb->prepare().
 */
function nibwp_events_audit_rows(string $sql, string $why, int &$skipped): array
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

        if (!nibwp_events_can_edit($id)) {
            $skipped++;
            continue;
        }

        $item = ['id' => $id, 'title' => (string) ($row['post_title'] ?? '')];

        foreach (['post_status' => 'status', 'start_date' => 'start_date', 'end_date' => 'end_date', 'end_date_utc' => 'end_date_utc'] as $from => $to) {
            if (isset($row[$from])) {
                $item[$to] = (string) $row[$from];
            }
        }

        if (isset($row['menu_order'])) {
            $item['sticky'] = -1 === (int) $row['menu_order'];
        }

        if (isset($row['featured'])) {
            $item['featured'] = nibwp_events_truthy($row['featured']);
        }

        $items[] = $item;
    }

    return ['why' => $why, 'count' => count($items), 'items' => $items];
}

/* ──────────────────────────── registration ───────────────────────────────── */

wp_register_ability('nibwp/events-manage', [
    'label' => __('The Events Calendar – Management', 'nibwp'),
    'description' => __(
        'Read and manage The Events Calendar. Events: list_events, get_event, create_event, update_event, set_event_status, trash_event, delete_event_permanently, list_occurrences. Venues: list_venues, get_venue, create_venue, update_venue, venue_events. Organizers: list_organizers, get_organizer, create_organizer, update_organizer, organizer_events. Terms: list_event_categories, list_event_tags, set_event_terms. Calendar: calendar_between, upcoming_events. And audit_calendar, which reports what is wrong with the calendar.',
        'nibwp'
    ),
    'category' => 'events',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => nibwp_events_actions(),
                'description' => 'The action to perform.',
            ],
            'event_id' => ['type' => 'integer', 'description' => 'Event post ID. Checked to really be an event before any permission is considered.'],
            'venue_id' => ['type' => 'integer', 'description' => 'Venue post ID.'],
            'organizer_id' => ['type' => 'integer', 'description' => 'Organizer post ID.'],
            'event_data' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'excerpt' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'description' => 'publish, draft, pending, private or future. New events default to draft.'],
                    'start_date' => ['type' => 'string', 'description' => 'Event-local start. Y-m-d H:i:s, Y-m-d H:i or Y-m-d.'],
                    'end_date' => ['type' => 'string', 'description' => 'Event-local end, same formats. Defaults to two hours after the start.'],
                    'timezone' => ['type' => 'string', 'description' => 'Olson name such as Europe/Rome, or a UTC offset. Defaults to the event\'s, then the site\'s.'],
                    'all_day' => ['type' => 'boolean'],
                    'venue_id' => ['type' => 'integer'],
                    'organizer_id' => ['type' => 'integer'],
                    'cost' => ['type' => 'string'],
                    'currency_symbol' => ['type' => 'string'],
                    'website' => ['type' => 'string'],
                    'url' => ['type' => 'string', 'description' => 'Alias of website, kept for existing callers.'],
                    'phone' => ['type' => 'string'],
                    'featured' => ['type' => 'boolean'],
                    'hide_from_upcoming' => ['type' => 'boolean'],
                ],
                'description' => 'Event fields for create_event and update_event.',
            ],
            'venue_data' => [
                'type' => 'object',
                'properties' => [
                    'venue' => ['type' => 'string', 'description' => 'Venue name.'],
                    'name' => ['type' => 'string', 'description' => 'Alias of venue.'],
                    'description' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'address' => ['type' => 'string'],
                    'city' => ['type' => 'string'],
                    'state' => ['type' => 'string'],
                    'province' => ['type' => 'string'],
                    'country' => ['type' => 'string'],
                    'zip' => ['type' => 'string'],
                    'phone' => ['type' => 'string'],
                    'website' => ['type' => 'string'],
                ],
                'description' => 'Venue fields for create_venue and update_venue.',
            ],
            'organizer_data' => [
                'type' => 'object',
                'properties' => [
                    'organizer' => ['type' => 'string', 'description' => 'Organizer name.'],
                    'name' => ['type' => 'string', 'description' => 'Alias of organizer.'],
                    'description' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                    'phone' => ['type' => 'string'],
                    'website' => ['type' => 'string'],
                ],
                'description' => 'Organizer fields for create_organizer and update_organizer.',
            ],
            'status' => ['type' => 'string', 'description' => 'Status filter for listings, or the new status for set_event_status.'],
            'from_date' => ['type' => 'string', 'description' => 'Range start. Y-m-d H:i:s, Y-m-d H:i or Y-m-d.'],
            'to_date' => ['type' => 'string', 'description' => 'Range end, same formats. A bare date means the whole of that day.'],
            'timezone' => ['type' => 'string', 'description' => 'Timezone the range dates are written in.'],
            'categories' => ['type' => 'array', 'description' => 'Term ids or names for set_event_terms. An empty array clears them.'],
            'tags' => ['type' => 'array', 'description' => 'Term ids or names for set_event_terms.'],
            'append' => ['type' => 'boolean', 'description' => 'Add to the existing terms rather than replacing them.'],
            'search' => ['type' => 'string', 'description' => 'Name search for venue and organizer listings.'],
            'count' => ['type' => 'integer', 'description' => 'How many upcoming events to return.'],
            'per_page' => ['type' => 'integer', 'default' => NIBWP_EVENTS_DEFAULT_ROWS, 'description' => 'Clamped to ' . NIBWP_EVENTS_MAX_ROWS . '.'],
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
            'venues' => ['type' => 'array'],
            'venue' => ['type' => 'object'],
            'organizers' => ['type' => 'array'],
            'organizer' => ['type' => 'object'],
            'occurrences' => ['type' => 'array'],
            'terms' => ['type' => 'array'],
            'by_day' => ['type' => 'object'],
            'findings' => ['type' => 'object'],
            'total' => ['type' => 'integer'],
            'skipped' => ['type' => 'integer'],
            'skipped_note' => ['type' => 'string'],
            'withheld_meta' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => 'nibwp_events_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Read and manage The Events Calendar: events, venues, organizers, categories and tags.',
                '',
                'EVENTS:',
                '- list_events: events in a date range, with a status filter. from_date/to_date/status/venue_id/organizer_id, per_page, page.',
                '- get_event: one event in full, with its venue, organizer, cost and timezone. Requires event_id.',
                '- create_event: needs event_data.title and event_data.start_date. Starts as a DRAFT unless event_data.status says otherwise.',
                '- update_event: requires event_id and event_data.',
                '- set_event_status: publish, draft, pending, private or future. Refuses to publish an event whose date meta is incomplete.',
                '- trash_event: reversible. Prefer this.',
                '- delete_event_permanently: IRREVERSIBLE. No trash, no undo; the event, its meta and its links to venues, organizers and any ticket records are gone. Ask the owner before calling it.',
                '- list_occurrences: the dates the calendar will draw for one event, plus whether it has a recurrence rule or belongs to a series.',
                '',
                'VENUES: list_venues, get_venue, create_venue, update_venue, venue_events (which events use one).',
                'ORGANIZERS: list_organizers, get_organizer, create_organizer, update_organizer, organizer_events.',
                'TERMS: list_event_categories, list_event_tags, set_event_terms (categories and/or tags, append optional).',
                'CALENDAR: calendar_between (needs from_date and to_date, groups by day), upcoming_events (the next count events from now).',
                'AUDIT: audit_calendar reports published events with no venue, events whose end precedes their start, past events still featured or sticky, venues and organizers nothing uses, and events with no date at all.',
                '',
                'DATES: send event-local time plus a timezone, never UTC. Accepted formats are Y-m-d H:i:s, Y-m-d\TH:i:s, Y-m-d H:i, Y-m-d\TH:i and Y-m-d, and nothing else — "next Friday" or "15/06/2026" is refused rather than guessed. An end before its start is refused, not reordered. The local time, the UTC mirror, the duration and the timezone are all written together; miss any of them and the event exists but never appears on the calendar.',
                '',
                'IDS: an id is checked to really be of the expected post type before any permission is considered, so handing an event action the id of a page refuses on the type and writes nothing.',
                '',
                'PERMISSIONS: every read and write asks edit_post or delete_post for that specific post. A listing silently containing fewer rows would be a lie, so it reports skipped and skipped_note.',
                '',
                'CREDENTIALS: event, venue and organizer meta leaves through an allow-list. Anything a ticketing or payment add-on stored on the same post is reported under withheld_meta as a key name with value_returned false, and its value never travels.',
                '',
                'RECURRENCE: occurrences are readable. Recurrence rules and series are written by the premium calendar add-on rather than by this plugin, so they are reported and not edited.',
                '',
                'REQUIRES: The Events Calendar must be active.',
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
 * wonder whether the plugin is there.
 */
function nibwp_events_manage(array $input): array|WP_Error
{
    $guard = nibwp_events_guard();

    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $action = is_string($input['action'] ?? null) ? $input['action'] : '';

    if (!in_array($action, nibwp_events_actions(), true)) {
        return nibwp_events_err(
            'unknown_action',
            sprintf(
                /* translators: 1: the action requested, 2: comma separated list of valid actions */
                __('Unknown action "%1$s". This hub offers: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_events_actions())
            )
        );
    }

    try {
        return nibwp_events_dispatch($action, $input);
    } catch (\Throwable $error) {
        // A throw from inside the calendar plugin is a refusal, not a 500 that
        // takes the whole REST request with it.
        return nibwp_events_err('plugin_error', $error->getMessage(), 500);
    }
}

/** One arm per action, and the check asserts the enum and this list agree. */
function nibwp_events_dispatch(string $action, array $input): array|WP_Error
{
    return match ($action) {
        'list_events' => nibwp_events_list_events($input),
        'get_event' => nibwp_events_get_event($input),
        'create_event' => nibwp_events_create_event($input),
        'update_event' => nibwp_events_update_event($input),
        'set_event_status' => nibwp_events_set_event_status($input),
        'trash_event' => nibwp_events_trash_event($input),
        'delete_event_permanently' => nibwp_events_delete_event_permanently($input),
        'list_occurrences' => nibwp_events_list_occurrences($input),
        'list_venues' => nibwp_events_list_linked('venue', $input),
        'get_venue' => nibwp_events_get_linked('venue', $input),
        'create_venue' => nibwp_events_create_linked('venue', $input),
        'update_venue' => nibwp_events_update_linked('venue', $input),
        'venue_events' => nibwp_events_linked_events('venue', $input),
        'list_organizers' => nibwp_events_list_linked('organizer', $input),
        'get_organizer' => nibwp_events_get_linked('organizer', $input),
        'create_organizer' => nibwp_events_create_linked('organizer', $input),
        'update_organizer' => nibwp_events_update_linked('organizer', $input),
        'organizer_events' => nibwp_events_linked_events('organizer', $input),
        'list_event_categories' => nibwp_events_list_terms(nibwp_events_taxonomy(), $input),
        'list_event_tags' => nibwp_events_list_terms('post_tag', $input),
        'set_event_terms' => nibwp_events_set_event_terms($input),
        'calendar_between' => nibwp_events_calendar_between($input),
        'upcoming_events' => nibwp_events_upcoming($input),
        'audit_calendar' => nibwp_events_audit($input),
        default => nibwp_events_err('unknown_action', __('Unknown action.', 'nibwp')),
    };
}
