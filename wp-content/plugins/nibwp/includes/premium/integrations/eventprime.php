<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: EventPrime.
 *
 * Verified against EventPrime 4.3.5.0 (event-prime.php:19, and the constant at
 * event-prime.php:38). `Eventprime_Basic_Functions` is the class every date, every
 * setting and every query in that plugin goes through, so between the constant and
 * the class we can tell "not installed" from "installed and half loaded".
 *
 * THE SHAPE OF THE DATA, because almost nothing here is where a reader expects.
 * Events, performers and bookings are post types — `em_event`, `em_performer`,
 * `em_booking` (admin/class-eventprime-event-calendar-management-admin.php:882,
 * :929, :985). But VENUES, EVENT TYPES AND ORGANIZERS ARE TAXONOMIES, not posts:
 * `em_venue`, `em_event_type`, `em_event_organizer`, all three attached to
 * `em_event` (same file, :703, :759, :815). An agent that assumes a venue is a post
 * will spend its day getting 404s. Their details live in TERM meta — `em_locality`,
 * `em_country`, `em_lat`, `em_lng` and friends (includes/class-eventprime-functions.php:9589-9596).
 * Tickets are not really a post type either: they are rows in `{prefix}em_price_options`
 * (includes/class-eventprime-event-calendar-management-activator.php:582, named at
 * :90 of the same file), and the `em_ticket` posts the plugin's own REST writer
 * creates (includes/class-eventprime-rest-api.php:2694) are a mirror that is never
 * registered as a post type at all. So tickets and capacity are read from the table.
 *
 * DATES ARE THE EXPENSIVE MISTAKE HERE, and it is a silent one: a date written in
 * the wrong shape produces an event that exists, lists in wp-admin, and never
 * appears on the calendar or in any shortcode. Two separate mechanisms do that.
 *
 *   - Every event query in the plugin sets `'meta_key' => 'em_start_date_time'`
 *     with `'orderby' => 'meta_value_num'` (includes/class-ep-ajax.php:3203,
 *     includes/class-eventprime-functions.php:6151, :9099, :11389, and ten more).
 *     `meta_key` is an INNER JOIN. An event with no `em_start_date_time` row is not
 *     sorted last — it is NOT IN THE RESULT SET AT ALL.
 *   - The calendar's own range filter compares `em_start_date` and `em_end_date`
 *     as `'type' => 'NUMERIC'` `BETWEEN` two UNIX timestamps
 *     (includes/class-ep-ajax.php:3216-3231). Store `'2026-06-15'` in those keys
 *     and MySQL casts it to 0, which is 1970 — inside no range anyone will ask for.
 *
 * So all four keys are integer UNIX timestamps and all four are written together.
 * That is also exactly what EventPrime's own REST writer does, in one breath:
 * `em_start_date` and `em_start_date_time` both get `$final_start_ts`, `em_end_date`
 * and `em_end_date_time` both get `$final_end_ts`
 * (includes/class-eventprime-rest-api.php:2502-2503).
 *
 * TIMEZONE. There is no per-event timezone in EventPrime. A wall-clock time is
 * interpreted in the SITE timezone and stored as the resulting epoch —
 * `ep_date_to_timestamp()` builds the DateTime with `new DateTimeZone( $site_timezone )`
 * (includes/class-eventprime-functions.php:2510-2528) and the REST writer uses
 * `wp_timezone()` for the same job (includes/class-eventprime-rest-api.php:2201).
 * So this file takes event-local time plus an optional timezone name, converts to
 * the epoch itself, and refuses a date it cannot parse rather than guessing. No
 * `strtotime()` anywhere: `strtotime('next friday')` succeeds, and an agent
 * guessing is how an event lands in the wrong week.
 *
 * ALL DAY. `em_all_day` is not decoration. When it is 1 the plugin forces the end
 * date back to the start date and rewrites both times to the day's bounds
 * (includes/class-ep-ajax.php:1116-1124), so we do the same thing on the way in
 * instead of leaving a half-day event that disagrees with itself. The two display
 * strings `em_start_time` / `em_end_time` follow the site's `time_format` setting —
 * `HH:mm` means 24-hour, anything else means 12-hour (includes/class-eventprime-global-settings.php:929).
 * Write them in the other shape and the event renders with a time nobody chose.
 *
 * BOOKINGS ARE PERSONAL DATA. A booking carries attendee names and whatever the
 * checkout form asked for, in `em_attendee_names` and `em_booking_fields_data`
 * (includes/class-ep-ajax.php:519-525). EventPrime registers `em_booking` with
 * `'capability_type' => 'em_booking'` and `'map_meta_cap' => false`
 * (admin/class-eventprime-event-calendar-management-admin.php:985-1020), which makes
 * `edit_em_bookings` the capability its bookings screen is reached with, and that
 * capability is granted to the administrator role and nobody else on activation
 * (includes/class-eventprime-event-calendar-management-activator.php:688-712). So
 * that is the capability asked for here. Not `edit_posts`, which every author has.
 * Counts and status breakdowns are preferred over rows throughout, every listing is
 * bounded, and attendee names never leave this file.
 *
 * NO REFUNDS, NO CANCELLATIONS. There is deliberately no action here that cancels
 * or refunds a booking somebody paid for. The plugin can do it
 * (includes/class-ep-bookings.php:113-119 writes the status and :905 writes
 * 'cancelled'), and an agent that can do it on a guess is a chargeback. Those two
 * decisions belong to a person with the order in front of them, two clicks away in
 * wp-admin. The check parses the action enum and fails if a name that reads like
 * either ever appears, so a later edit has to argue with it.
 *
 * CREDENTIALS. `em_global_settings` is one flat object holding display preferences
 * next to `paypal_client_id` and `paypal_client_secret`
 * (includes/class-eventprime-global-settings.php:332-338), and the plugin keeps its
 * REST key in `ep_rest_api_key` and its encryption material in
 * `ep_encrypt_secret_key` / `ep_encrypt_secret_iv`. Post and term meta are no safer:
 * a ticketing add-on writes to the same post as the event. So EVERY value that
 * leaves here passes an ALLOW-LIST — an allow-list rather than a denylist because
 * add-ons add keys we have never seen on a schedule we do not control, and a
 * denylist defaults to leaking each new one. A key that is not on the list is
 * reported as a name and whether it is set, never as a value. A key that IS on the
 * list but holds an array or an object is DROPPED rather than flattened, because
 * casting a structure to a string is how a serialised order record becomes a leak.
 */

/** The door for events: the capability the plugin itself asks for (includes/class-eventprime-functions.php, two sites). */
const NIBWP_EP_EVENT_CAP = 'edit_em_events';

/**
 * The door for bookings.
 *
 * `em_booking` is registered with `capability_type => 'em_booking'` and
 * `map_meta_cap => false`, so this plural capability IS the bookings screen.
 */
const NIBWP_EP_BOOKING_CAP = 'edit_em_bookings';

/** Venues, event types and organizers are taxonomies, and this is their manage_terms capability. */
const NIBWP_EP_TERM_CAP = 'manage_em_event_terms';

/** Performers are their own post type with their own capability family. */
const NIBWP_EP_PERFORMER_CAP = 'edit_em_performers';

/** EventPrime's own settings screen is registered with this and nothing weaker (admin:3334). */
const NIBWP_EP_SETTINGS_CAP = 'manage_options';

/** How many rows any listing returns, whatever was asked for. */
const NIBWP_EP_MAX_ROWS = 200;

/** What a listing returns when the caller did not say. */
const NIBWP_EP_DEFAULT_ROWS = 20;

/** The event post type. */
const NIBWP_EP_EVENT_TYPE = 'em_event';

/** The booking post type. */
const NIBWP_EP_BOOKING_TYPE = 'em_booking';

/** The performer post type. */
const NIBWP_EP_PERFORMER_TYPE = 'em_performer';

/**
 * Is EventPrime actually loaded?
 *
 * The constant is defined by the main plugin file before anything else, and the
 * functions class is what every date and every setting read below goes through.
 */
function nibwp_eventprime_guard(): ?WP_Error
{
    if (!defined('EVENTPRIME_VERSION') && !class_exists('Eventprime_Basic_Functions')) {
        return new WP_Error(
            'nibwp_eventprime_missing',
            __('EventPrime is not active on this site.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/** A refusal about the request itself. */
function nibwp_eventprime_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error('nibwp_eventprime_' . $code, $message, ['status' => $status]);
}

/**
 * A refusal about authority rather than about the request.
 *
 * The capability is named in the message on purpose: an owner who sees this needs
 * to know which role to look at, and the name is not a secret — the ids and status
 * values this refusal fires BEFORE reaching are.
 */
function nibwp_eventprime_forbidden(string $what, string $cap): WP_Error
{
    return new WP_Error(
        'nibwp_eventprime_forbidden',
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
 * The door, asked BEFORE any input is looked at.
 *
 * Order matters more than it looks. Validate first and an unauthorised caller
 * learns from the refusal which ids exist and which status values the plugin
 * accepts — a free schema read for anybody with a login.
 */
function nibwp_eventprime_require(string $what, string $cap): ?WP_Error
{
    return current_user_can($cap) ? null : nibwp_eventprime_forbidden($what, $cap);
}

/**
 * An id from a caller is a claim, and this is where the claim is tested.
 *
 * The post type is resolved FIRST and ALONE, before any capability is consulted.
 * Handed the id of a page, a delete must refuse on the type and must not have asked
 * anybody whether they may delete pages — otherwise the refusal leaks that the
 * account lacks a capability for an object it was never going to be allowed near.
 *
 * @return array{post: WP_Post}|WP_Error
 */
function nibwp_eventprime_resolve(int $id, string $kind): array|WP_Error
{
    $expected = nibwp_eventprime_post_type($kind);

    if ($id <= 0) {
        return nibwp_eventprime_err(
            'bad_request',
            sprintf(
                /* translators: %s: the kind of record, e.g. event */
                __('A positive %s id is required.', 'nibwp'),
                $kind
            )
        );
    }

    $post = get_post($id);

    if (!$post instanceof WP_Post) {
        return nibwp_eventprime_err(
            'no_such_post',
            sprintf(
                /* translators: %d: post ID */
                __('Nothing with the id %d exists on this site.', 'nibwp'),
                $id
            ),
            404
        );
    }

    if ($post->post_type !== $expected) {
        $code = NIBWP_EP_BOOKING_TYPE === $expected
            ? 'not_a_booking'
            : (NIBWP_EP_PERFORMER_TYPE === $expected ? 'not_a_performer' : 'not_an_event');

        return nibwp_eventprime_err(
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

/** Post type name for a kind this hub speaks about. */
function nibwp_eventprime_post_type(string $kind): string
{
    return match ($kind) {
        'booking' => NIBWP_EP_BOOKING_TYPE,
        'performer' => NIBWP_EP_PERFORMER_TYPE,
        default => NIBWP_EP_EVENT_TYPE,
    };
}

/** Taxonomy name for a kind this hub speaks about. */
function nibwp_eventprime_taxonomy(string $kind): string
{
    return match ($kind) {
        'event_type' => 'em_event_type',
        'organizer' => 'em_event_organizer',
        default => 'em_venue',
    };
}

/** A term id is a claim too, and the taxonomy is checked before any capability. */
function nibwp_eventprime_resolve_term(int $id, string $kind): array|WP_Error
{
    $taxonomy = nibwp_eventprime_taxonomy($kind);

    if ($id <= 0) {
        return nibwp_eventprime_err(
            'bad_request',
            sprintf(
                /* translators: %s: the kind of record, e.g. venue */
                __('A positive %s id is required.', 'nibwp'),
                $kind
            )
        );
    }

    $term = get_term($id);

    if (!$term instanceof WP_Term) {
        return nibwp_eventprime_err(
            'no_such_term',
            sprintf(
                /* translators: %d: term ID */
                __('No term with the id %d exists on this site.', 'nibwp'),
                $id
            ),
            404
        );
    }

    if ($term->taxonomy !== $taxonomy) {
        return nibwp_eventprime_err(
            'wrong_taxonomy',
            sprintf(
                /* translators: 1: term ID, 2: actual taxonomy, 3: expected taxonomy */
                __('Term %1$d belongs to "%2$s", not to "%3$s". Refusing on the taxonomy before considering permissions.', 'nibwp'),
                $id,
                (string) $term->taxonomy,
                $taxonomy
            ),
            409
        );
    }

    return ['term' => $term];
}

/** May this account edit this one post? */
function nibwp_eventprime_can_edit(int $id): bool
{
    return current_user_can('edit_post', $id);
}

/** May this account delete this one post? */
function nibwp_eventprime_can_delete(int $id): bool
{
    return current_user_can('delete_post', $id);
}

/* ─────────────────────────── dates and timezones ─────────────────────────── */

/** The only date shapes accepted. A format not on this list is refused, never guessed. */
function nibwp_eventprime_date_formats(): array
{
    return ['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i', 'Y-m-d'];
}

/**
 * The meta keys an event needs written TOGETHER to exist as far as EventPrime is
 * concerned. Miss any one of them and the event is in wp-admin and nowhere else.
 */
function nibwp_eventprime_required_date_meta(): array
{
    return [
        'em_start_date',
        'em_start_date_time',
        'em_end_date',
        'em_end_date_time',
        'em_start_time',
        'em_end_time',
        'em_all_day',
    ];
}

/** The site timezone, which is the only timezone EventPrime stores an event in. */
function nibwp_eventprime_site_timezone(): DateTimeZone
{
    try {
        if (function_exists('wp_timezone')) {
            $zone = wp_timezone();

            if ($zone instanceof DateTimeZone) {
                return $zone;
            }
        }

        if (function_exists('wp_timezone_string')) {
            $name = (string) wp_timezone_string();

            if ('' !== $name) {
                return new DateTimeZone($name);
            }
        }
    } catch (\Throwable $error) {
        // Fall through to UTC: a bad site setting is not a reason to 500.
    }

    return new DateTimeZone('UTC');
}

/** A named timezone from the caller, or the site's. */
function nibwp_eventprime_zone(string $name): DateTimeZone|WP_Error
{
    if ('' === trim($name)) {
        return nibwp_eventprime_site_timezone();
    }

    try {
        return new DateTimeZone(trim($name));
    } catch (\Throwable $error) {
        return nibwp_eventprime_err(
            'bad_timezone',
            sprintf(
                /* translators: %s: the timezone that was sent */
                __('"%s" is not a timezone this site knows. Send an Olson name such as Europe/Rome.', 'nibwp'),
                $name
            )
        );
    }
}

/**
 * Parse one event-local date into the epoch EventPrime stores.
 *
 * @return array{ts: int, date_only: bool, local: string}|WP_Error
 */
function nibwp_eventprime_parse_date(string $value, DateTimeZone $zone): array|WP_Error
{
    $value = trim($value);

    if ('' === $value) {
        return nibwp_eventprime_err('bad_date', __('A date is required and was empty.', 'nibwp'));
    }

    foreach (nibwp_eventprime_date_formats() as $format) {
        $parsed = DateTimeImmutable::createFromFormat('!' . $format, $value, $zone);

        if (!$parsed instanceof DateTimeImmutable) {
            continue;
        }

        $errors = DateTimeImmutable::getLastErrors();

        if (is_array($errors) && ((int) ($errors['warning_count'] ?? 0) > 0 || (int) ($errors['error_count'] ?? 0) > 0)) {
            continue;
        }

        return [
            'ts' => $parsed->getTimestamp(),
            'date_only' => 'Y-m-d' === $format,
            'local' => $parsed->format('Y-m-d H:i:s'),
        ];
    }

    return nibwp_eventprime_err(
        'bad_date',
        sprintf(
            /* translators: 1: the date that was sent, 2: comma separated list of accepted formats */
            __('"%1$s" is not a date this hub will guess at. Accepted: %2$s.', 'nibwp'),
            $value,
            implode(', ', nibwp_eventprime_date_formats())
        )
    );
}

/** EventPrime's own global settings, as an array, without letting its internals throw. */
function nibwp_eventprime_settings(): array
{
    try {
        $raw = get_option('em_global_settings');
    } catch (\Throwable $error) {
        return [];
    }

    if (is_array($raw)) {
        return $raw;
    }

    // The plugin stores this option as a stdClass (includes/class-eventprime-functions.php:1835-1846).
    return is_object($raw) ? get_object_vars($raw) : [];
}

/**
 * The PHP time format that matches the site's EventPrime time setting.
 *
 * `HH:mm` means 24-hour, and every other value means 12-hour
 * (includes/class-eventprime-global-settings.php:929).
 */
function nibwp_eventprime_time_format(): string
{
    $settings = nibwp_eventprime_settings();
    $setting = isset($settings['time_format']) && is_scalar($settings['time_format'])
        ? (string) $settings['time_format']
        : '';

    return 'HH:mm' === $setting ? 'H:i' : 'h:i A';
}

/**
 * Write the whole coherent date set for one event.
 *
 * Every key in nibwp_eventprime_required_date_meta() is written on every call, in
 * one place, so there is no path through this file that produces half of them.
 *
 * @return array<string, int|string>
 */
function nibwp_eventprime_write_dates(int $event_id, int $start_ts, int $end_ts, bool $all_day): array
{
    $zone = nibwp_eventprime_site_timezone();
    $format = nibwp_eventprime_time_format();

    $start = (new DateTimeImmutable('@' . $start_ts))->setTimezone($zone);
    $end = (new DateTimeImmutable('@' . $end_ts))->setTimezone($zone);

    if ($all_day) {
        // The plugin does exactly this when em_all_day is 1, and an event that
        // disagrees with the plugin about its own bounds renders twice.
        $start = $start->setTime(0, 0, 0);
        $end = $start->setTime(23, 59, 0);
    }

    $written = [
        'em_start_date' => $start->getTimestamp(),
        'em_start_date_time' => $start->getTimestamp(),
        'em_end_date' => $end->getTimestamp(),
        'em_end_date_time' => $end->getTimestamp(),
        'em_start_time' => $start->format($format),
        'em_end_time' => $end->format($format),
        'em_all_day' => $all_day ? 1 : 0,
    ];

    foreach ($written as $key => $value) {
        update_post_meta($event_id, $key, $value);
    }

    return $written;
}

/** Is every mandatory date key present and numeric where it must be? */
function nibwp_eventprime_dates_complete(int $event_id): bool
{
    foreach (['em_start_date', 'em_start_date_time', 'em_end_date', 'em_end_date_time'] as $key) {
        $value = get_post_meta($event_id, $key, true);

        if (!is_numeric($value) || (int) $value <= 0) {
            return false;
        }
    }

    return '' !== (string) get_post_meta($event_id, 'em_start_time', true);
}

/** The dates of one event, reported in site-local time next to the raw epochs. */
function nibwp_eventprime_read_dates(int $event_id): array
{
    $zone = nibwp_eventprime_site_timezone();
    $start = (int) get_post_meta($event_id, 'em_start_date_time', true);
    $end = (int) get_post_meta($event_id, 'em_end_date_time', true);

    $out = [
        'all_day' => 1 === (int) get_post_meta($event_id, 'em_all_day', true),
        'timezone' => $zone->getName(),
        'start_timestamp' => $start,
        'end_timestamp' => $end,
        'start_local' => $start > 0 ? (new DateTimeImmutable('@' . $start))->setTimezone($zone)->format('Y-m-d H:i:s') : '',
        'end_local' => $end > 0 ? (new DateTimeImmutable('@' . $end))->setTimezone($zone)->format('Y-m-d H:i:s') : '',
        'start_time_display' => (string) get_post_meta($event_id, 'em_start_time', true),
        'end_time_display' => (string) get_post_meta($event_id, 'em_end_time', true),
        'complete' => nibwp_eventprime_dates_complete($event_id),
    ];

    if (!$out['complete']) {
        $out['note'] = __('This event is missing part of its date set, so EventPrime will not list it anywhere. Send update_event with a start_date to repair it.', 'nibwp');
    }

    return $out;
}

/* ───────────────────────────── the allow-lists ───────────────────────────── */

/** Event meta whose VALUE may travel. Everything else is a name and is_set. */
function nibwp_eventprime_event_meta_allowed(): array
{
    return [
        'em_id' => true,
        'em_name' => true,
        'em_start_date' => true,
        'em_start_date_time' => true,
        'em_end_date' => true,
        'em_end_date_time' => true,
        'em_start_time' => true,
        'em_end_time' => true,
        'em_all_day' => true,
        'em_venue' => true,
        'em_event_type' => true,
        'em_organizer' => true,
        'em_performer' => true,
        'em_enable_booking' => true,
        'em_fixed_event_price' => true,
        'em_is_featured' => true,
        'em_hide_end_date' => true,
        'em_hide_event_start_time' => true,
        'em_hide_event_end_time' => true,
        'em_event_more_dates' => true,
        'em_allow_cancellations' => true,
        'em_frontend_submission' => true,
        'em_user_submitted' => true,
        'em_custom_link' => true,
        'em_event_date_placeholder' => true,
    ];
}

/**
 * Booking meta whose VALUE may travel.
 *
 * Short on purpose. `em_attendee_names` and `em_booking_fields_data` hold the
 * attendee's name and whatever the checkout form asked them; `em_order_info` holds
 * the order record; `em_payment_log` holds the gateway's own replies; `ep_order_key`
 * is a bearer token that reaches a booking page without logging in. None of them
 * are on this list and none of them ever will be.
 */
function nibwp_eventprime_booking_meta_allowed(): array
{
    return [
        'em_id' => true,
        'em_event' => true,
        'em_status' => true,
        'em_date' => true,
        'em_payment_method' => true,
        'ep_gdpr_consent' => true,
        'ep_gdpr_consent_time' => true,
    ];
}

/** Performer meta whose VALUE may travel. */
function nibwp_eventprime_performer_meta_allowed(): array
{
    return [
        'em_id' => true,
        'em_name' => true,
        'em_is_featured' => true,
        'em_established' => true,
        'em_age_group' => true,
        'em_type' => true,
    ];
}

/** Term meta whose VALUE may travel, for venues, event types and organizers. */
function nibwp_eventprime_term_meta_allowed(): array
{
    return [
        'em_status' => true,
        'em_is_featured' => true,
        'em_color' => true,
        'em_type_text_color' => true,
        'em_locality' => true,
        'em_state' => true,
        'em_country' => true,
        'em_postal_code' => true,
        'em_lat' => true,
        'em_lng' => true,
        'em_zoom_level' => true,
        'em_display_address_on_frontend' => true,
        'em_established' => true,
        'em_age_group' => true,
        'em_seating_organizer' => true,
    ];
}

/**
 * Global settings whose VALUE may travel.
 *
 * `paypal_client_id`, `paypal_client_secret` and anything a payment or mail add-on
 * filtered into this option are absent, and absent is the whole mechanism: the
 * reporter below never looks for them by name, it only ever looks for the names on
 * this list.
 */
function nibwp_eventprime_settings_allowed(): array
{
    return [
        'eventprime_theme' => true,
        'time_format' => true,
        'datepicker_format' => true,
        'currency' => true,
        'currency_position' => true,
        'payment_test_mode' => true,
        'default_payment_processor' => true,
        'default_booking_status' => true,
        'required_booking_attendee_name' => true,
        'hide_0_price_from_frontend' => true,
        'enable_event_time_to_user_timezone' => true,
        'show_timezone_message_on_event_page' => true,
        'checkout_page_timer' => true,
        'show_qr_code_on_ticket' => true,
        'shortcode_hide_upcoming_events' => true,
    ];
}

/**
 * Is there anything there?
 *
 * Deliberately never casts an object to a string: the whole point of this file's
 * allow-list is that a structure's contents do not travel, and a __toString on a
 * gateway response object would hand them over through the back door.
 */
function nibwp_eventprime_is_set(mixed $value): bool
{
    if (null === $value) {
        return false;
    }

    if (is_array($value)) {
        return [] !== $value;
    }

    if (is_object($value)) {
        return true;
    }

    return '' !== (string) $value;
}

/**
 * Report a bag of values through an allow-list.
 *
 * Three outcomes, and the middle one is the one that matters. A key on the list
 * holding a scalar travels. A key on the list holding an ARRAY OR AN OBJECT is
 * dropped, not cast — `(string) $array` is "Array", but `(string)` on a structure
 * that happens to hold one string is the whole secret, and which of those you get
 * depends on data we do not control. A key not on the list is reported as a name
 * and whether it is set, so a caller can see something is there without being
 * handed it.
 *
 * @param array<string, mixed> $values
 * @param array<string, bool>  $allowed
 */
function nibwp_eventprime_filter_values(array $values, array $allowed): array
{
    $out = [];
    $withheld = [];

    foreach ($values as $key => $value) {
        $key = (string) $key;

        if (!isset($allowed[$key])) {
            $withheld[] = [
                'key' => $key,
                'is_set' => nibwp_eventprime_is_set($value),
                'value_returned' => false,
                'why' => __('Not on the allow-list.', 'nibwp'),
            ];
            continue;
        }

        if (!is_scalar($value)) {
            $withheld[] = [
                'key' => $key,
                'is_set' => nibwp_eventprime_is_set($value),
                'value_returned' => false,
                'why' => __('Allowed by name but holds a structure, which is dropped rather than flattened.', 'nibwp'),
            ];
            continue;
        }

        $out[$key] = $value;
    }

    return ['values' => $out, 'withheld' => $withheld];
}

/** Post meta for one post, through an allow-list. */
function nibwp_eventprime_post_meta(int $post_id, array $allowed): array
{
    $raw = get_post_meta($post_id);
    $flat = [];

    if (is_array($raw)) {
        foreach ($raw as $key => $values) {
            $flat[(string) $key] = is_array($values) && array_key_exists(0, $values) && 1 === count($values)
                ? $values[0]
                : $values;
        }
    }

    return nibwp_eventprime_filter_values($flat, $allowed);
}

/** Term meta for one term, through an allow-list. */
function nibwp_eventprime_term_meta(int $term_id): array
{
    $raw = get_term_meta($term_id);
    $flat = [];

    if (is_array($raw)) {
        foreach ($raw as $key => $values) {
            $flat[(string) $key] = is_array($values) && array_key_exists(0, $values) && 1 === count($values)
                ? $values[0]
                : $values;
        }
    }

    return nibwp_eventprime_filter_values($flat, nibwp_eventprime_term_meta_allowed());
}

/* ──────────────────────────────── bounding ───────────────────────────────── */

/** How many rows this listing may return. Always bounded, including by default. */
function nibwp_eventprime_per_page(array $input): int
{
    $asked = isset($input['per_page']) && is_numeric($input['per_page'])
        ? (int) $input['per_page']
        : NIBWP_EP_DEFAULT_ROWS;

    if ($asked < 1) {
        $asked = NIBWP_EP_DEFAULT_ROWS;
    }

    return min($asked, NIBWP_EP_MAX_ROWS);
}

/** Which page of a bounded listing. */
function nibwp_eventprime_page(array $input): int
{
    $page = isset($input['page']) && is_numeric($input['page']) ? (int) $input['page'] : 1;

    return max(1, $page);
}

/** The post statuses a listing may ask for. */
function nibwp_eventprime_statuses(): array
{
    return ['publish', 'draft', 'pending', 'private', 'future', 'trash', 'any'];
}

/** The booking statuses EventPrime writes (includes/class-ep-bookings.php:887-911). */
function nibwp_eventprime_booking_statuses(): array
{
    return ['completed', 'pending', 'cancelled', 'refunded'];
}

/** A status filter from a caller, validated AFTER the capability was asked for. */
function nibwp_eventprime_status(array $input, string $fallback = 'any'): string|WP_Error
{
    if (!isset($input['status']) || '' === trim((string) $input['status'])) {
        return $fallback;
    }

    $status = sanitize_key((string) $input['status']);

    if (!in_array($status, nibwp_eventprime_statuses(), true)) {
        return nibwp_eventprime_err(
            'bad_status',
            sprintf(
                /* translators: 1: the status that was sent, 2: comma separated list of valid statuses */
                __('"%1$s" is not a status. This hub accepts: %2$s.', 'nibwp'),
                (string) $input['status'],
                implode(', ', nibwp_eventprime_statuses())
            )
        );
    }

    return $status;
}

/**
 * A date range for a listing, either end optional.
 *
 * @return array{from: ?int, to: ?int, zone: string}|WP_Error
 */
function nibwp_eventprime_range(array $input): array|WP_Error
{
    $zone = nibwp_eventprime_zone(isset($input['timezone']) ? (string) $input['timezone'] : '');

    if ($zone instanceof WP_Error) {
        return $zone;
    }

    $from = null;
    $to = null;

    if (isset($input['from_date']) && '' !== trim((string) $input['from_date'])) {
        $parsed = nibwp_eventprime_parse_date((string) $input['from_date'], $zone);

        if ($parsed instanceof WP_Error) {
            return $parsed;
        }

        $from = $parsed['ts'];
    }

    if (isset($input['to_date']) && '' !== trim((string) $input['to_date'])) {
        $parsed = nibwp_eventprime_parse_date((string) $input['to_date'], $zone);

        if ($parsed instanceof WP_Error) {
            return $parsed;
        }

        // A bare date means the whole of that day, which is what anybody asking
        // "up to the 30th" means and not what a naive midnight would give them.
        $to = $parsed['date_only'] ? $parsed['ts'] + DAY_IN_SECONDS - 1 : $parsed['ts'];
    }

    if (null !== $from && null !== $to && $to < $from) {
        return nibwp_eventprime_err('bad_range', __('The end of the range is before its start.', 'nibwp'));
    }

    return ['from' => $from, 'to' => $to, 'zone' => $zone->getName()];
}

/**
 * The meta query the CALENDAR uses, not one of our own invention.
 *
 * Numeric BETWEEN on em_start_date / em_end_date, exactly as
 * includes/class-ep-ajax.php:3216-3231 builds it, so a range read here returns the
 * same events the calendar would draw.
 */
function nibwp_eventprime_range_meta_query(?int $from, ?int $to): array
{
    if (null === $from && null === $to) {
        return [];
    }

    $low = $from ?? 0;
    $high = $to ?? 2147483647;

    return [
        'relation' => 'OR',
        [
            'key' => 'em_start_date',
            'value' => [$low, $high],
            'compare' => 'BETWEEN',
            'type' => 'NUMERIC',
        ],
        [
            'key' => 'em_end_date',
            'value' => [$low, $high],
            'compare' => 'BETWEEN',
            'type' => 'NUMERIC',
        ],
    ];
}

/** One event, as a listing row. */
function nibwp_eventprime_event_summary(WP_Post $post): array
{
    $dates = nibwp_eventprime_read_dates($post->ID);

    return [
        'id' => $post->ID,
        'title' => $post->post_title,
        'status' => $post->post_status,
        'start_local' => $dates['start_local'],
        'end_local' => $dates['end_local'],
        'all_day' => $dates['all_day'],
        'timezone' => $dates['timezone'],
        'dates_complete' => $dates['complete'],
        'venue' => nibwp_eventprime_event_term_names($post->ID, 'venue'),
        'event_types' => nibwp_eventprime_event_term_names($post->ID, 'event_type'),
        'booking_enabled' => '' !== (string) get_post_meta($post->ID, 'em_enable_booking', true)
            && '0' !== (string) get_post_meta($post->ID, 'em_enable_booking', true),
    ];
}

/** Term names attached to one event, for one of the three taxonomies. */
function nibwp_eventprime_event_term_names(int $event_id, string $kind): array
{
    $terms = get_the_terms($event_id, nibwp_eventprime_taxonomy($kind));

    if (!is_array($terms)) {
        return [];
    }

    $out = [];

    foreach ($terms as $term) {
        if (!is_object($term) || !isset($term->term_id)) {
            continue;
        }

        $out[] = ['id' => (int) $term->term_id, 'name' => (string) ($term->name ?? '')];
    }

    return $out;
}

/* ──────────────────────────────── events ─────────────────────────────────── */

/** Events in a date range, with a status filter, bounded. */
function nibwp_eventprime_list_events(array $input): array|WP_Error
{
    $denied = nibwp_eventprime_require(__('Listing events', 'nibwp'), NIBWP_EP_EVENT_CAP);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $status = nibwp_eventprime_status($input);

    if ($status instanceof WP_Error) {
        return $status;
    }

    $range = nibwp_eventprime_range($input);

    if ($range instanceof WP_Error) {
        return $range;
    }

    $per_page = nibwp_eventprime_per_page($input);
    $page = nibwp_eventprime_page($input);

    $args = [
        'post_type' => NIBWP_EP_EVENT_TYPE,
        'post_status' => $status,
        'posts_per_page' => $per_page,
        'offset' => ($page - 1) * $per_page,
        'meta_key' => 'em_start_date_time',
        'orderby' => 'meta_value_num',
        'order' => 'ASC',
        'suppress_filters' => false,
    ];

    $meta_query = nibwp_eventprime_range_meta_query($range['from'], $range['to']);

    if ([] !== $meta_query) {
        $args['meta_query'] = [$meta_query];
    }

    $posts = get_posts($args);
    $items = [];
    $skipped = 0;

    foreach (is_array($posts) ? $posts : [] as $post) {
        if (!$post instanceof WP_Post) {
            continue;
        }

        if (!nibwp_eventprime_can_edit($post->ID)) {
            ++$skipped;
            continue;
        }

        $items[] = nibwp_eventprime_event_summary($post);
    }

    $out = [
        'events' => $items,
        'count' => count($items),
        'per_page' => $per_page,
        'page' => $page,
        'range' => $range,
        'note' => __('Ordered by em_start_date_time, which is an inner join: an event missing that key is absent from this list and from EventPrime\'s own.', 'nibwp'),
    ];

    if ($skipped > 0) {
        $out['skipped'] = $skipped;
        $out['skipped_note'] = __('Rows this account may not edit were left out rather than silently shortening the list.', 'nibwp');
    }

    return $out;
}

/** One event in full. */
function nibwp_eventprime_get_event(array $input): array|WP_Error
{
    $resolved = nibwp_eventprime_resolve((int) ($input['event_id'] ?? 0), 'event');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $post = $resolved['post'];

    if (!nibwp_eventprime_can_edit($post->ID)) {
        return nibwp_eventprime_forbidden(__('Reading this event', 'nibwp'), 'edit_post');
    }

    $meta = nibwp_eventprime_post_meta($post->ID, nibwp_eventprime_event_meta_allowed());

    return [
        'event' => [
            'id' => $post->ID,
            'title' => $post->post_title,
            'status' => $post->post_status,
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt,
            'permalink' => get_permalink($post->ID),
            'dates' => nibwp_eventprime_read_dates($post->ID),
            'venue' => nibwp_eventprime_event_term_names($post->ID, 'venue'),
            'event_types' => nibwp_eventprime_event_term_names($post->ID, 'event_type'),
            'organizers' => nibwp_eventprime_event_term_names($post->ID, 'organizer'),
            'meta' => $meta['values'],
        ],
        'withheld_meta' => $meta['withheld'],
    ];
}

/**
 * Create one event.
 *
 * Starts as a DRAFT unless the caller says otherwise, because an agent that
 * publishes on a guess puts a wrong date in front of the public.
 */
function nibwp_eventprime_create_event(array $input): array|WP_Error
{
    $denied = nibwp_eventprime_require(__('Creating an event', 'nibwp'), NIBWP_EP_EVENT_CAP);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $data = isset($input['event_data']) && is_array($input['event_data']) ? $input['event_data'] : [];

    if ([] === $data) {
        return nibwp_eventprime_err('bad_request', __('event_data is required and was empty.', 'nibwp'));
    }

    $title = isset($data['title']) ? sanitize_text_field((string) $data['title']) : '';

    if ('' === $title) {
        return nibwp_eventprime_err('bad_request', __('event_data.title is required.', 'nibwp'));
    }

    if (!isset($data['start_date']) || '' === trim((string) $data['start_date'])) {
        return nibwp_eventprime_err('bad_request', __('event_data.start_date is required. An event with no start date exists nowhere EventPrime can see it.', 'nibwp'));
    }

    $bounds = nibwp_eventprime_bounds($data);

    if ($bounds instanceof WP_Error) {
        return $bounds;
    }

    $status = isset($data['status']) ? sanitize_key((string) $data['status']) : 'draft';

    if (!in_array($status, ['publish', 'draft', 'pending', 'private', 'future'], true)) {
        return nibwp_eventprime_err('bad_status', __('An event may be created as publish, draft, pending, private or future.', 'nibwp'));
    }

    $post = [
        'post_type' => NIBWP_EP_EVENT_TYPE,
        'post_title' => $title,
        'post_status' => $status,
    ];

    if (isset($data['description'])) {
        // wp_slash and nothing else: wp_insert_post unslashes, and a kses pass of
        // our own on top of the one WordPress already applies by role eats the
        // owner's markup for no gain.
        $post['post_content'] = wp_slash((string) $data['description']);
    }

    if (isset($data['excerpt'])) {
        $post['post_excerpt'] = wp_slash(sanitize_text_field((string) $data['excerpt']));
    }

    $event_id = wp_insert_post($post, true);

    if ($event_id instanceof WP_Error) {
        return $event_id;
    }

    $event_id = (int) $event_id;

    if ($event_id <= 0) {
        return nibwp_eventprime_err('insert_failed', __('WordPress refused to create the event.', 'nibwp'), 500);
    }

    // EventPrime reads these two off meta rather than off the post
    // (includes/class-eventprime-functions.php:6161-6166), so an event without
    // them reads as nameless in its own listings.
    update_post_meta($event_id, 'em_id', $event_id);
    update_post_meta($event_id, 'em_name', $title);

    $written = nibwp_eventprime_write_dates($event_id, $bounds['start'], $bounds['end'], $bounds['all_day']);
    $links = nibwp_eventprime_apply_links($event_id, $data);

    return [
        'created' => true,
        'event_id' => $event_id,
        'status' => $status,
        'date_meta_written' => $written,
        'links' => $links,
        'dates' => nibwp_eventprime_read_dates($event_id),
    ];
}

/** Update one event. */
function nibwp_eventprime_update_event(array $input): array|WP_Error
{
    $resolved = nibwp_eventprime_resolve((int) ($input['event_id'] ?? 0), 'event');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $post = $resolved['post'];

    if (!nibwp_eventprime_can_edit($post->ID)) {
        return nibwp_eventprime_forbidden(__('Updating this event', 'nibwp'), 'edit_post');
    }

    $data = isset($input['event_data']) && is_array($input['event_data']) ? $input['event_data'] : [];

    if ([] === $data) {
        return nibwp_eventprime_err('bad_request', __('event_data is required and was empty.', 'nibwp'));
    }

    $fields = ['ID' => $post->ID];

    if (isset($data['title'])) {
        $title = sanitize_text_field((string) $data['title']);

        if ('' === $title) {
            return nibwp_eventprime_err('bad_request', __('event_data.title cannot be emptied.', 'nibwp'));
        }

        $fields['post_title'] = $title;
    }

    if (isset($data['description'])) {
        $fields['post_content'] = wp_slash((string) $data['description']);
    }

    if (isset($data['excerpt'])) {
        $fields['post_excerpt'] = wp_slash(sanitize_text_field((string) $data['excerpt']));
    }

    $written = [];

    if (isset($data['start_date']) || isset($data['end_date']) || isset($data['all_day'])) {
        $bounds = nibwp_eventprime_bounds($data, $post->ID);

        if ($bounds instanceof WP_Error) {
            return $bounds;
        }

        $written = nibwp_eventprime_write_dates($post->ID, $bounds['start'], $bounds['end'], $bounds['all_day']);
    }

    if (count($fields) > 1) {
        $updated = wp_update_post($fields, true);

        if ($updated instanceof WP_Error) {
            return $updated;
        }

        if (isset($fields['post_title'])) {
            update_post_meta($post->ID, 'em_name', $fields['post_title']);
        }
    }

    $links = nibwp_eventprime_apply_links($post->ID, $data);

    return [
        'updated' => true,
        'event_id' => $post->ID,
        'date_meta_written' => $written,
        'links' => $links,
        'dates' => nibwp_eventprime_read_dates($post->ID),
    ];
}

/**
 * Work out the start and end epochs for a write.
 *
 * An end before its start is REFUSED rather than reordered. EventPrime's own
 * frontend writer silently pulls the end back to the start when all_day is set
 * (includes/class-ep-ajax.php:1118-1120), and a caller who sent the wrong month
 * deserves to hear about it rather than to get a one-second event.
 *
 * @return array{start: int, end: int, all_day: bool}|WP_Error
 */
function nibwp_eventprime_bounds(array $data, int $event_id = 0): array|WP_Error
{
    $zone = nibwp_eventprime_zone(isset($data['timezone']) ? (string) $data['timezone'] : '');

    if ($zone instanceof WP_Error) {
        return $zone;
    }

    $all_day = isset($data['all_day'])
        ? (bool) $data['all_day']
        : ($event_id > 0 && 1 === (int) get_post_meta($event_id, 'em_all_day', true));

    $start = null;

    if (isset($data['start_date']) && '' !== trim((string) $data['start_date'])) {
        $parsed = nibwp_eventprime_parse_date((string) $data['start_date'], $zone);

        if ($parsed instanceof WP_Error) {
            return $parsed;
        }

        $start = $parsed['ts'];
    } elseif ($event_id > 0) {
        $existing = (int) get_post_meta($event_id, 'em_start_date_time', true);
        $start = $existing > 0 ? $existing : null;
    }

    if (null === $start) {
        return nibwp_eventprime_err('bad_request', __('A start_date is required: this event has none stored to fall back on.', 'nibwp'));
    }

    $end = null;

    if (isset($data['end_date']) && '' !== trim((string) $data['end_date'])) {
        $parsed = nibwp_eventprime_parse_date((string) $data['end_date'], $zone);

        if ($parsed instanceof WP_Error) {
            return $parsed;
        }

        // A bare end date means the end of that day, the same way a range does.
        $end = $parsed['date_only'] ? $parsed['ts'] + DAY_IN_SECONDS - 60 : $parsed['ts'];
    } elseif ($event_id > 0) {
        $existing = (int) get_post_meta($event_id, 'em_end_date_time', true);
        $end = $existing > $start ? $existing : null;
    }

    if (null === $end) {
        $end = $start + (2 * HOUR_IN_SECONDS);
    }

    if ($end < $start) {
        return nibwp_eventprime_err(
            'end_before_start',
            __('The end is before the start. Refusing rather than reordering them, because a swapped pair is usually a wrong month.', 'nibwp')
        );
    }

    return ['start' => $start, 'end' => $end, 'all_day' => $all_day];
}

/** Attach venue, event type and organizer terms named in a write payload. */
function nibwp_eventprime_apply_links(int $event_id, array $data): array
{
    $applied = [];

    foreach (['venue' => 'venue_id', 'event_type' => 'event_type_id', 'organizer' => 'organizer_id'] as $kind => $field) {
        if (!isset($data[$field]) || !is_numeric($data[$field])) {
            continue;
        }

        $term_id = (int) $data[$field];

        if ($term_id <= 0) {
            continue;
        }

        $resolved = nibwp_eventprime_resolve_term($term_id, $kind);

        if ($resolved instanceof WP_Error) {
            $applied[$kind] = ['set' => false, 'why' => $resolved->get_error_message()];
            continue;
        }

        wp_set_object_terms($event_id, [$term_id], nibwp_eventprime_taxonomy($kind), false);

        // The plugin also keeps the id in post meta and reads it back from there
        // in several places, so both have to agree.
        update_post_meta($event_id, 'venue' === $kind ? 'em_venue' : ('organizer' === $kind ? 'em_organizer' : 'em_event_type'), $term_id);
        $applied[$kind] = ['set' => true, 'term_id' => $term_id];
    }

    return $applied;
}

/** Move one event between statuses. */
function nibwp_eventprime_set_event_status(array $input): array|WP_Error
{
    $resolved = nibwp_eventprime_resolve((int) ($input['event_id'] ?? 0), 'event');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $post = $resolved['post'];

    if (!nibwp_eventprime_can_edit($post->ID)) {
        return nibwp_eventprime_forbidden(__('Changing this event\'s status', 'nibwp'), 'edit_post');
    }

    $status = isset($input['status']) ? sanitize_key((string) $input['status']) : '';

    if (!in_array($status, ['publish', 'draft', 'pending', 'private', 'future'], true)) {
        return nibwp_eventprime_err('bad_status', __('A status of publish, draft, pending, private or future is required.', 'nibwp'));
    }

    if ('publish' === $status && !nibwp_eventprime_dates_complete($post->ID)) {
        return nibwp_eventprime_err(
            'incomplete_dates',
            __('Refusing to publish an event whose date set is incomplete: it would be live and invisible. Send update_event with a start_date first.', 'nibwp')
        );
    }

    $updated = wp_update_post(['ID' => $post->ID, 'post_status' => $status], true);

    if ($updated instanceof WP_Error) {
        return $updated;
    }

    return ['event_id' => $post->ID, 'status' => $status];
}

/** Trash one event. Reversible, and the preferred way to retire one. */
function nibwp_eventprime_trash_event(array $input): array|WP_Error
{
    $resolved = nibwp_eventprime_resolve((int) ($input['event_id'] ?? 0), 'event');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $post = $resolved['post'];

    if (!nibwp_eventprime_can_delete($post->ID)) {
        return nibwp_eventprime_forbidden(__('Trashing this event', 'nibwp'), 'delete_post');
    }

    wp_trash_post($post->ID);

    return [
        'event_id' => $post->ID,
        'trashed' => true,
        'note' => __('The event is in the trash and can be restored from wp-admin. Its bookings are untouched.', 'nibwp'),
    ];
}

/**
 * Delete one event for good, and say out loud what goes with it.
 *
 * A booking stores its event as the meta value `em_event` on the booking post
 * (includes/class-ep-ajax.php:494). Deleting the event does not delete those posts,
 * but it ORPHANS every one of them: the booking can no longer name what it is a
 * booking for, the attendee list for that event is gone, and nothing in the plugin
 * will put it back. So the count is read FIRST and reported, and the description
 * says it before the call is made rather than after.
 */
function nibwp_eventprime_delete_event(array $input): array|WP_Error
{
    $resolved = nibwp_eventprime_resolve((int) ($input['event_id'] ?? 0), 'event');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $post = $resolved['post'];

    if (!nibwp_eventprime_can_delete($post->ID)) {
        return nibwp_eventprime_forbidden(__('Deleting this event', 'nibwp'), 'delete_post');
    }

    if (true !== ($input['confirm'] ?? null)) {
        return nibwp_eventprime_err(
            'confirm_required',
            __('This is irreversible and it orphans every booking for the event. Send confirm true once the owner has agreed.', 'nibwp')
        );
    }

    $counts = nibwp_eventprime_count_for_event($post->ID);

    wp_delete_post($post->ID, true);

    return [
        'event_id' => $post->ID,
        'deleted' => true,
        'bookings_orphaned' => $counts['total'],
        'note' => __('Permanent. The event, its meta, its date set and its links to venue, type and organizer are gone, and every booking that pointed at it now points at nothing. Those bookings still hold attendee data and are listed by audit_events as orphans.', 'nibwp'),
    ];
}

/* ───────────────────── venues, event types, organizers ───────────────────── */

/** One bounded listing, shared by all three taxonomies. */
function nibwp_eventprime_list_terms(string $kind, array $input): array|WP_Error
{
    $denied = nibwp_eventprime_require(__('Listing venues, event types and organizers', 'nibwp'), NIBWP_EP_TERM_CAP);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $per_page = nibwp_eventprime_per_page($input);
    $search = isset($input['search']) ? sanitize_text_field((string) $input['search']) : '';

    $args = [
        'taxonomy' => nibwp_eventprime_taxonomy($kind),
        'hide_empty' => false,
        'number' => $per_page,
        'offset' => (nibwp_eventprime_page($input) - 1) * $per_page,
    ];

    if ('' !== $search) {
        $args['search'] = $search;
    }

    $terms = get_terms($args);
    $items = [];

    foreach (is_array($terms) ? $terms : [] as $term) {
        if (!is_object($term) || !isset($term->term_id)) {
            continue;
        }

        $items[] = [
            'id' => (int) $term->term_id,
            'name' => (string) ($term->name ?? ''),
            'slug' => (string) ($term->slug ?? ''),
            'parent' => (int) ($term->parent ?? 0),
            'event_count' => (int) ($term->count ?? 0),
        ];
    }

    return [
        'kind' => $kind,
        'taxonomy' => nibwp_eventprime_taxonomy($kind),
        'terms' => $items,
        'count' => count($items),
        'per_page' => $per_page,
        'note' => __('In EventPrime a venue, an event type and an organizer are taxonomy terms, not posts. Their ids are term ids.', 'nibwp'),
    ];
}

/** One venue in full, including its address term meta. */
function nibwp_eventprime_get_venue(array $input): array|WP_Error
{
    $resolved = nibwp_eventprime_resolve_term((int) ($input['venue_id'] ?? 0), 'venue');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $denied = nibwp_eventprime_require(__('Reading a venue', 'nibwp'), NIBWP_EP_TERM_CAP);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $term = $resolved['term'];
    $meta = nibwp_eventprime_term_meta((int) $term->term_id);

    return [
        'venue' => [
            'id' => (int) $term->term_id,
            'name' => (string) $term->name,
            'slug' => (string) $term->slug,
            'description' => (string) ($term->description ?? ''),
            'event_count' => (int) ($term->count ?? 0),
            'meta' => $meta['values'],
        ],
        'withheld_meta' => $meta['withheld'],
    ];
}

/** Create a venue, event type or organizer term. */
function nibwp_eventprime_create_term(string $kind, array $input): array|WP_Error
{
    $denied = nibwp_eventprime_require(__('Creating a venue, event type or organizer', 'nibwp'), NIBWP_EP_TERM_CAP);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $name = isset($input['name']) ? sanitize_text_field((string) $input['name']) : '';

    if ('' === $name) {
        return nibwp_eventprime_err('bad_request', __('A name is required.', 'nibwp'));
    }

    $args = [];

    if (isset($input['description'])) {
        $args['description'] = sanitize_text_field((string) $input['description']);
    }

    $created = wp_insert_term($name, nibwp_eventprime_taxonomy($kind), $args);

    if ($created instanceof WP_Error) {
        return $created;
    }

    $term_id = is_array($created) ? (int) ($created['term_id'] ?? 0) : 0;

    if ($term_id <= 0) {
        return nibwp_eventprime_err('insert_failed', __('WordPress refused to create the term.', 'nibwp'), 500);
    }

    nibwp_eventprime_write_term_meta($term_id, $input);

    return ['created' => true, 'kind' => $kind, 'term_id' => $term_id, 'name' => $name];
}

/** Rename or re-describe a venue, and write its allow-listed address meta. */
function nibwp_eventprime_update_venue(array $input): array|WP_Error
{
    $resolved = nibwp_eventprime_resolve_term((int) ($input['venue_id'] ?? 0), 'venue');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $denied = nibwp_eventprime_require(__('Updating a venue', 'nibwp'), NIBWP_EP_TERM_CAP);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $term = $resolved['term'];
    $args = [];

    if (isset($input['name'])) {
        $name = sanitize_text_field((string) $input['name']);

        if ('' === $name) {
            return nibwp_eventprime_err('bad_request', __('A venue name cannot be emptied.', 'nibwp'));
        }

        $args['name'] = $name;
    }

    if (isset($input['description'])) {
        $args['description'] = sanitize_text_field((string) $input['description']);
    }

    if ([] !== $args) {
        $updated = wp_update_term((int) $term->term_id, 'em_venue', $args);

        if ($updated instanceof WP_Error) {
            return $updated;
        }
    }

    $meta_written = nibwp_eventprime_write_term_meta((int) $term->term_id, $input);

    return ['updated' => true, 'venue_id' => (int) $term->term_id, 'meta_written' => $meta_written];
}

/** Write only the term meta on the allow-list, and only scalars. */
function nibwp_eventprime_write_term_meta(int $term_id, array $input): array
{
    $meta = isset($input['meta']) && is_array($input['meta']) ? $input['meta'] : [];
    $allowed = nibwp_eventprime_term_meta_allowed();
    $written = [];

    foreach ($meta as $key => $value) {
        $key = (string) $key;

        if (!isset($allowed[$key]) || !is_scalar($value)) {
            continue;
        }

        update_term_meta($term_id, $key, sanitize_text_field((string) $value));
        $written[] = $key;
    }

    return $written;
}

/* ─────────────────────────────── performers ──────────────────────────────── */

/** Performers, bounded. */
function nibwp_eventprime_list_performers(array $input): array|WP_Error
{
    $denied = nibwp_eventprime_require(__('Listing performers', 'nibwp'), NIBWP_EP_PERFORMER_CAP);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $status = nibwp_eventprime_status($input);

    if ($status instanceof WP_Error) {
        return $status;
    }

    $per_page = nibwp_eventprime_per_page($input);
    $posts = get_posts([
        'post_type' => NIBWP_EP_PERFORMER_TYPE,
        'post_status' => $status,
        'posts_per_page' => $per_page,
        'offset' => (nibwp_eventprime_page($input) - 1) * $per_page,
        'orderby' => 'title',
        'order' => 'ASC',
        'suppress_filters' => false,
    ]);

    $items = [];

    foreach (is_array($posts) ? $posts : [] as $post) {
        if (!$post instanceof WP_Post) {
            continue;
        }

        $items[] = ['id' => $post->ID, 'name' => $post->post_title, 'status' => $post->post_status];
    }

    return ['performers' => $items, 'count' => count($items), 'per_page' => $per_page];
}

/** One performer. */
function nibwp_eventprime_get_performer(array $input): array|WP_Error
{
    $resolved = nibwp_eventprime_resolve((int) ($input['performer_id'] ?? 0), 'performer');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $post = $resolved['post'];

    if (!nibwp_eventprime_can_edit($post->ID)) {
        return nibwp_eventprime_forbidden(__('Reading this performer', 'nibwp'), 'edit_post');
    }

    $meta = nibwp_eventprime_post_meta($post->ID, nibwp_eventprime_performer_meta_allowed());

    return [
        'performer' => [
            'id' => $post->ID,
            'name' => $post->post_title,
            'status' => $post->post_status,
            'content' => $post->post_content,
            'meta' => $meta['values'],
        ],
        'withheld_meta' => $meta['withheld'],
    ];
}

/** Create a performer. */
function nibwp_eventprime_create_performer(array $input): array|WP_Error
{
    $denied = nibwp_eventprime_require(__('Creating a performer', 'nibwp'), NIBWP_EP_PERFORMER_CAP);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $name = isset($input['name']) ? sanitize_text_field((string) $input['name']) : '';

    if ('' === $name) {
        return nibwp_eventprime_err('bad_request', __('A name is required.', 'nibwp'));
    }

    $post = [
        'post_type' => NIBWP_EP_PERFORMER_TYPE,
        'post_title' => $name,
        'post_status' => 'publish',
    ];

    if (isset($input['description'])) {
        $post['post_content'] = wp_slash((string) $input['description']);
    }

    $performer_id = wp_insert_post($post, true);

    if ($performer_id instanceof WP_Error) {
        return $performer_id;
    }

    $performer_id = (int) $performer_id;

    if ($performer_id <= 0) {
        return nibwp_eventprime_err('insert_failed', __('WordPress refused to create the performer.', 'nibwp'), 500);
    }

    update_post_meta($performer_id, 'em_id', $performer_id);
    update_post_meta($performer_id, 'em_name', $name);

    return ['created' => true, 'performer_id' => $performer_id, 'name' => $name];
}

/* ──────────────────────────────── bookings ───────────────────────────────── */

/**
 * Bookings, bounded, and never with an attendee's name in them.
 *
 * Takes the capability EventPrime's own bookings screen is reached with. A row
 * here is the order's shape — who it is for is deliberately not in it.
 */
function nibwp_eventprime_list_bookings(array $input): array|WP_Error
{
    $event_id = isset($input['event_id']) && is_numeric($input['event_id']) ? (int) $input['event_id'] : 0;

    if ($event_id > 0) {
        // The filter carries an id, and an id is a claim: the type is settled
        // before anyone is asked whether they may read bookings.
        $resolved = nibwp_eventprime_resolve($event_id, 'event');

        if ($resolved instanceof WP_Error) {
            return $resolved;
        }
    }

    $denied = nibwp_eventprime_require(__('Listing bookings', 'nibwp'), NIBWP_EP_BOOKING_CAP);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $booking_status = nibwp_eventprime_booking_status_filter($input);

    if ($booking_status instanceof WP_Error) {
        return $booking_status;
    }

    $per_page = nibwp_eventprime_per_page($input);
    $args = [
        'post_type' => NIBWP_EP_BOOKING_TYPE,
        'post_status' => 'any',
        'posts_per_page' => $per_page,
        'offset' => (nibwp_eventprime_page($input) - 1) * $per_page,
        'orderby' => 'date',
        'order' => 'DESC',
        'suppress_filters' => false,
    ];

    $meta_query = [];

    if ($event_id > 0) {
        $meta_query[] = ['key' => 'em_event', 'value' => $event_id, 'compare' => '=', 'type' => 'NUMERIC'];
    }

    if ('' !== $booking_status) {
        $meta_query[] = ['key' => 'em_status', 'value' => $booking_status, 'compare' => '='];
    }

    if ([] !== $meta_query) {
        $meta_query['relation'] = 'AND';
        $args['meta_query'] = $meta_query;
    }

    $posts = get_posts($args);
    $items = [];
    $withheld = [];

    foreach (is_array($posts) ? $posts : [] as $post) {
        if (!$post instanceof WP_Post) {
            continue;
        }

        $meta = nibwp_eventprime_post_meta($post->ID, nibwp_eventprime_booking_meta_allowed());
        $items[] = [
            'id' => $post->ID,
            'post_status' => $post->post_status,
            'event_id' => (int) get_post_meta($post->ID, 'em_event', true),
            'booking_status' => (string) get_post_meta($post->ID, 'em_status', true),
            'meta' => $meta['values'],
        ];

        foreach ($meta['withheld'] as $row) {
            $withheld[(string) $row['key']] = $row;
        }
    }

    return [
        'bookings' => $items,
        'count' => count($items),
        'per_page' => $per_page,
        'withheld_meta' => array_values($withheld),
        'note' => __('Attendee names, checkout answers, the order record, the payment log and the booking\'s order key are not on the allow-list and do not leave this hub at any size of listing.', 'nibwp'),
    ];
}

/** A booking status filter, validated after the capability. */
function nibwp_eventprime_booking_status_filter(array $input): string|WP_Error
{
    if (!isset($input['booking_status']) || '' === trim((string) $input['booking_status'])) {
        return '';
    }

    $status = sanitize_key((string) $input['booking_status']);

    if (!in_array($status, nibwp_eventprime_booking_statuses(), true)) {
        return nibwp_eventprime_err(
            'bad_booking_status',
            sprintf(
                /* translators: 1: the status that was sent, 2: comma separated list of valid statuses */
                __('"%1$s" is not a booking status. EventPrime writes: %2$s.', 'nibwp'),
                (string) $input['booking_status'],
                implode(', ', nibwp_eventprime_booking_statuses())
            )
        );
    }

    return $status;
}

/** One booking, through the allow-list. */
function nibwp_eventprime_get_booking(array $input): array|WP_Error
{
    $resolved = nibwp_eventprime_resolve((int) ($input['booking_id'] ?? 0), 'booking');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $post = $resolved['post'];
    $denied = nibwp_eventprime_require(__('Reading a booking', 'nibwp'), NIBWP_EP_BOOKING_CAP);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    if (!nibwp_eventprime_can_edit($post->ID)) {
        return nibwp_eventprime_forbidden(__('Reading this booking', 'nibwp'), 'edit_post');
    }

    $meta = nibwp_eventprime_post_meta($post->ID, nibwp_eventprime_booking_meta_allowed());
    $event_id = (int) get_post_meta($post->ID, 'em_event', true);
    $event = $event_id > 0 ? get_post($event_id) : null;

    return [
        'booking' => [
            'id' => $post->ID,
            'post_status' => $post->post_status,
            'booking_status' => (string) get_post_meta($post->ID, 'em_status', true),
            'event_id' => $event_id,
            'event_title' => $event instanceof WP_Post && NIBWP_EP_EVENT_TYPE === $event->post_type ? $event->post_title : '',
            'event_missing' => $event_id > 0 && !($event instanceof WP_Post && NIBWP_EP_EVENT_TYPE === $event->post_type),
            'attendee_count' => nibwp_eventprime_attendee_count($post->ID),
            'meta' => $meta['values'],
        ],
        'withheld_meta' => $meta['withheld'],
        'note' => __('Attendee count only. The names behind it stay in wp-admin.', 'nibwp'),
    ];
}

/**
 * How many attendees one booking holds.
 *
 * `em_attendee_names` is keyed by ticket id and holds one entry per attendee
 * (includes/class-ep-ajax.php:519). Only the count comes out of this function, by
 * construction: there is no path through it that returns a name.
 */
function nibwp_eventprime_attendee_count(int $booking_id): int
{
    $raw = get_post_meta($booking_id, 'em_attendee_names', true);

    if (!is_array($raw)) {
        return 0;
    }

    $count = 0;

    foreach ($raw as $per_ticket) {
        $count += is_array($per_ticket) ? count($per_ticket) : 1;
    }

    return $count;
}

/**
 * Bookings over a period, as counts by status.
 *
 * Straight SQL against postmeta because the alternative is loading every booking
 * post in the period to add up four numbers. Every value is bound through
 * prepare(); the two table names come off $wpdb and never off the request.
 */
function nibwp_eventprime_count_bookings(array $input): array|WP_Error
{
    $denied = nibwp_eventprime_require(__('Counting bookings', 'nibwp'), NIBWP_EP_BOOKING_CAP);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $range = nibwp_eventprime_range($input);

    if ($range instanceof WP_Error) {
        return $range;
    }

    global $wpdb;

    if (!is_object($wpdb)) {
        return nibwp_eventprime_err('no_database', __('No database handle is available.', 'nibwp'), 500);
    }

    $from = $range['from'] ?? 0;
    $to = $range['to'] ?? 2147483647;

    // em_date on a booking is a timestamp written at checkout (includes/class-ep-ajax.php:495).
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT s.meta_value AS booking_status, COUNT(*) AS total'
            . ' FROM ' . $wpdb->postmeta . ' AS d'
            . ' INNER JOIN ' . $wpdb->posts . ' AS p ON p.ID = d.post_id'
            . ' LEFT JOIN ' . $wpdb->postmeta . ' AS s ON s.post_id = d.post_id AND s.meta_key = %s'
            . ' WHERE d.meta_key = %s AND p.post_type = %s'
            . ' AND CAST(d.meta_value AS UNSIGNED) BETWEEN %d AND %d'
            . ' GROUP BY s.meta_value LIMIT %d',
            'em_status',
            'em_date',
            NIBWP_EP_BOOKING_TYPE,
            (int) $from,
            (int) $to,
            NIBWP_EP_MAX_ROWS
        ),
        ARRAY_A
    );

    $by_status = [];
    $total = 0;

    foreach (is_array($rows) ? $rows : [] as $row) {
        $status = (string) ($row['booking_status'] ?? '');
        $count = (int) ($row['total'] ?? 0);
        $by_status['' === $status ? 'unknown' : $status] = $count;
        $total += $count;
    }

    return [
        'range' => $range,
        'total' => $total,
        'by_status' => $by_status,
        'note' => __('Counts only, over the booking\'s own em_date timestamp. No row of this answer names a person.', 'nibwp'),
    ];
}

/** Booking counts for one event, used by three actions and the audit. */
function nibwp_eventprime_count_for_event(int $event_id): array
{
    global $wpdb;

    $out = ['total' => 0, 'by_status' => [], 'active' => 0, 'attendees' => 0];

    if (!is_object($wpdb)) {
        return $out;
    }

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT s.meta_value AS booking_status, COUNT(*) AS total'
            . ' FROM ' . $wpdb->postmeta . ' AS e'
            . ' INNER JOIN ' . $wpdb->posts . ' AS p ON p.ID = e.post_id'
            . ' LEFT JOIN ' . $wpdb->postmeta . ' AS s ON s.post_id = e.post_id AND s.meta_key = %s'
            . ' WHERE e.meta_key = %s AND e.meta_value = %s AND p.post_type = %s'
            . ' GROUP BY s.meta_value LIMIT %d',
            'em_status',
            'em_event',
            (string) $event_id,
            NIBWP_EP_BOOKING_TYPE,
            NIBWP_EP_MAX_ROWS
        ),
        ARRAY_A
    );

    foreach (is_array($rows) ? $rows : [] as $row) {
        $status = (string) ($row['booking_status'] ?? '');
        $count = (int) ($row['total'] ?? 0);
        $out['by_status']['' === $status ? 'unknown' : $status] = $count;
        $out['total'] += $count;

        if (in_array($status, ['completed', 'pending'], true) || '' === $status) {
            $out['active'] += $count;
        }
    }

    return $out;
}

/**
 * Attendance for one event: how many people are expected, by booking status.
 *
 * Per-attendee check-in state is not readable here and the response says so. It is
 * written by a separate add-on — the plugin itself only reaches it through the
 * `attendee_check_in_filter` hook (admin/partials/event-attendee-list.php:79) and
 * checks for the extension by slug (includes/class-eventprime-rest-api.php:328-339).
 * Inventing a number for it would be worse than not having one.
 */
function nibwp_eventprime_event_attendance(array $input): array|WP_Error
{
    $resolved = nibwp_eventprime_resolve((int) ($input['event_id'] ?? 0), 'event');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $post = $resolved['post'];
    $denied = nibwp_eventprime_require(__('Reading attendance', 'nibwp'), NIBWP_EP_BOOKING_CAP);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    if (!nibwp_eventprime_can_edit($post->ID)) {
        return nibwp_eventprime_forbidden(__('Reading this event\'s attendance', 'nibwp'), 'edit_post');
    }

    $counts = nibwp_eventprime_count_for_event($post->ID);
    $per_page = nibwp_eventprime_per_page($input);

    $bookings = get_posts([
        'post_type' => NIBWP_EP_BOOKING_TYPE,
        'post_status' => 'any',
        'posts_per_page' => $per_page,
        'fields' => 'ids',
        'meta_query' => [
            ['key' => 'em_event', 'value' => $post->ID, 'compare' => '=', 'type' => 'NUMERIC'],
        ],
        'suppress_filters' => false,
    ]);

    $attendees = 0;

    foreach (is_array($bookings) ? $bookings : [] as $booking_id) {
        $attendees += nibwp_eventprime_attendee_count((int) $booking_id);
    }

    return [
        'event_id' => $post->ID,
        'bookings_total' => $counts['total'],
        'bookings_by_status' => $counts['by_status'],
        'bookings_active' => $counts['active'],
        'attendees_counted' => $attendees,
        'attendees_counted_over' => $per_page,
        'check_in_state' => [
            'available' => false,
            'why' => __('Per-attendee check-in is written by EventPrime\'s check-in add-on behind a filter, not in meta this hub can read. No number is invented for it.', 'nibwp'),
        ],
    ];
}

/* ───────────────────────── tickets, seats, capacity ──────────────────────── */

/** The ticket table name. Built from $wpdb, never from input. */
function nibwp_eventprime_tickets_table(): string
{
    global $wpdb;

    return is_object($wpdb) ? $wpdb->prefix . 'em_price_options' : '';
}

/** Does the ticket table exist on this install? */
function nibwp_eventprime_tickets_table_exists(): bool
{
    global $wpdb;

    $table = nibwp_eventprime_tickets_table();

    if ('' === $table || !is_object($wpdb)) {
        return false;
    }

    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

    return is_string($found) && '' !== $found;
}

/**
 * Tickets for one event, read from the table that owns them.
 *
 * Only scalar columns, and deliberately not `seat_data`, `offers`, `visibility`
 * or `additional_fees` — all four are longtext JSON a seating or pricing add-on
 * wrote, and handing an agent a blob it will paste somewhere is how a price
 * structure ends up in a support ticket. Their presence is reported instead.
 */
function nibwp_eventprime_list_tickets(array $input): array|WP_Error
{
    $resolved = nibwp_eventprime_resolve((int) ($input['event_id'] ?? 0), 'event');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $post = $resolved['post'];

    if (!nibwp_eventprime_can_edit($post->ID)) {
        return nibwp_eventprime_forbidden(__('Reading this event\'s tickets', 'nibwp'), 'edit_post');
    }

    if (!nibwp_eventprime_tickets_table_exists()) {
        return nibwp_eventprime_err(
            'no_ticket_table',
            __('EventPrime\'s ticket table is not present, so this install has no ticket rows to read.', 'nibwp'),
            409
        );
    }

    global $wpdb;

    $per_page = nibwp_eventprime_per_page($input);
    $table = nibwp_eventprime_tickets_table();

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT id, name, price, special_price, capacity, status, is_default, is_event_price,'
            . ' min_ticket_no, max_ticket_no, allow_cancellation, category_id,'
            . ' CHAR_LENGTH(COALESCE(seat_data, %s)) AS seat_data_length,'
            . ' CHAR_LENGTH(COALESCE(offers, %s)) AS offers_length'
            . ' FROM ' . $table . ' WHERE event_id = %d ORDER BY priority ASC, id ASC LIMIT %d',
            '',
            '',
            $post->ID,
            $per_page
        ),
        ARRAY_A
    );

    $items = [];

    foreach (is_array($rows) ? $rows : [] as $row) {
        $items[] = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'price' => (string) ($row['price'] ?? ''),
            'special_price' => (string) ($row['special_price'] ?? ''),
            'capacity' => null === ($row['capacity'] ?? null) ? null : (int) $row['capacity'],
            'active' => 1 === (int) ($row['status'] ?? 0),
            'is_default' => 1 === (int) ($row['is_default'] ?? 0),
            'is_event_price' => 1 === (int) ($row['is_event_price'] ?? 0),
            'min_per_order' => (string) ($row['min_ticket_no'] ?? ''),
            'max_per_order' => (string) ($row['max_ticket_no'] ?? ''),
            'cancellable' => 1 === (int) ($row['allow_cancellation'] ?? 0),
            'category_id' => (int) ($row['category_id'] ?? 0),
            'has_seat_map' => (int) ($row['seat_data_length'] ?? 0) > 0,
            'has_offers' => (int) ($row['offers_length'] ?? 0) > 0,
        ];
    }

    return [
        'event_id' => $post->ID,
        'tickets' => $items,
        'count' => count($items),
        'per_page' => $per_page,
        'note' => __('Seat maps, offers, visibility rules and additional fees are reported as present or absent. Their stored JSON does not travel.', 'nibwp'),
    ];
}

/**
 * Seat and capacity state for one event.
 *
 * Capacity in EventPrime is PER TICKET, not per event, so the total here is the sum
 * of the active ticket rows' capacities and the comparison against bookings is a
 * FLOOR rather than a seat count: one booking can hold several tickets. The response
 * says that rather than presenting a guess as a number.
 */
function nibwp_eventprime_event_capacity(array $input): array|WP_Error
{
    $resolved = nibwp_eventprime_resolve((int) ($input['event_id'] ?? 0), 'event');

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $post = $resolved['post'];

    if (!nibwp_eventprime_can_edit($post->ID)) {
        return nibwp_eventprime_forbidden(__('Reading this event\'s capacity', 'nibwp'), 'edit_post');
    }

    $capacity = nibwp_eventprime_capacity_total($post->ID);
    $counts = nibwp_eventprime_count_for_event($post->ID);
    $booking_mode = (string) get_post_meta($post->ID, 'em_enable_booking', true);

    return [
        'event_id' => $post->ID,
        'capacity_total' => $capacity['total'],
        'capacity_known' => $capacity['known'],
        'ticket_rows' => $capacity['rows'],
        'seat_maps' => $capacity['with_seat_map'],
        'bookings_active' => $counts['active'],
        'bookings_total' => $counts['total'],
        'booking_enabled' => '' !== $booking_mode && '0' !== $booking_mode,
        'at_capacity' => $capacity['known'] && $capacity['total'] > 0 && $counts['active'] >= $capacity['total'],
        'note' => __('Capacity in EventPrime is per ticket row, and one booking can hold several tickets, so bookings_active is a floor on seats taken rather than an exact count.', 'nibwp'),
    ];
}

/** Summed ticket capacity for one event. */
function nibwp_eventprime_capacity_total(int $event_id): array
{
    $out = ['total' => 0, 'known' => false, 'rows' => 0, 'with_seat_map' => 0];

    if (!nibwp_eventprime_tickets_table_exists()) {
        return $out;
    }

    global $wpdb;

    $table = nibwp_eventprime_tickets_table();

    $row = $wpdb->get_row(
        $wpdb->prepare(
            'SELECT COUNT(*) AS rows_found, SUM(COALESCE(capacity, 0)) AS capacity_sum,'
            . ' SUM(CASE WHEN CHAR_LENGTH(COALESCE(seat_data, %s)) > 0 THEN 1 ELSE 0 END) AS seat_maps'
            . ' FROM ' . $table . ' WHERE event_id = %d AND status = %d',
            '',
            $event_id,
            1
        ),
        ARRAY_A
    );

    if (!is_array($row)) {
        return $out;
    }

    $out['rows'] = (int) ($row['rows_found'] ?? 0);
    $out['total'] = (int) ($row['capacity_sum'] ?? 0);
    $out['with_seat_map'] = (int) ($row['seat_maps'] ?? 0);
    $out['known'] = $out['rows'] > 0;

    return $out;
}

/* ──────────────────────────────── calendar ───────────────────────────────── */

/** Events between two dates, grouped by the day the calendar would draw them on. */
function nibwp_eventprime_calendar_between(array $input): array|WP_Error
{
    $denied = nibwp_eventprime_require(__('Reading the calendar', 'nibwp'), NIBWP_EP_EVENT_CAP);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $range = nibwp_eventprime_range($input);

    if ($range instanceof WP_Error) {
        return $range;
    }

    if (null === $range['from'] || null === $range['to']) {
        return nibwp_eventprime_err('bad_request', __('calendar_between needs both from_date and to_date.', 'nibwp'));
    }

    $per_page = nibwp_eventprime_per_page($input);
    $zone = nibwp_eventprime_site_timezone();

    $posts = get_posts([
        'post_type' => NIBWP_EP_EVENT_TYPE,
        'post_status' => 'publish',
        'posts_per_page' => $per_page,
        'meta_key' => 'em_start_date_time',
        'orderby' => 'meta_value_num',
        'order' => 'ASC',
        'meta_query' => [nibwp_eventprime_range_meta_query($range['from'], $range['to'])],
        'suppress_filters' => false,
    ]);

    $by_day = [];
    $count = 0;
    $skipped = 0;

    foreach (is_array($posts) ? $posts : [] as $post) {
        if (!$post instanceof WP_Post) {
            continue;
        }

        if (!nibwp_eventprime_can_edit($post->ID)) {
            ++$skipped;
            continue;
        }

        $start = (int) get_post_meta($post->ID, 'em_start_date_time', true);
        $day = $start > 0 ? (new DateTimeImmutable('@' . $start))->setTimezone($zone)->format('Y-m-d') : 'no-date';
        $by_day[$day][] = ['id' => $post->ID, 'title' => $post->post_title];
        ++$count;
    }

    $out = ['range' => $range, 'by_day' => $by_day, 'count' => $count, 'per_page' => $per_page];

    if ($skipped > 0) {
        $out['skipped'] = $skipped;
        $out['skipped_note'] = __('Events this account may not edit were left out.', 'nibwp');
    }

    return $out;
}

/** The next events from now. */
function nibwp_eventprime_upcoming_events(array $input): array|WP_Error
{
    $denied = nibwp_eventprime_require(__('Reading upcoming events', 'nibwp'), NIBWP_EP_EVENT_CAP);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $per_page = nibwp_eventprime_per_page($input);
    $now = time();

    $posts = get_posts([
        'post_type' => NIBWP_EP_EVENT_TYPE,
        'post_status' => 'publish',
        'posts_per_page' => $per_page,
        'meta_key' => 'em_start_date_time',
        'orderby' => 'meta_value_num',
        'order' => 'ASC',
        'meta_query' => [
            ['key' => 'em_end_date', 'value' => $now, 'compare' => '>=', 'type' => 'NUMERIC'],
        ],
        'suppress_filters' => false,
    ]);

    $items = [];
    $skipped = 0;

    foreach (is_array($posts) ? $posts : [] as $post) {
        if (!$post instanceof WP_Post) {
            continue;
        }

        if (!nibwp_eventprime_can_edit($post->ID)) {
            ++$skipped;
            continue;
        }

        $items[] = nibwp_eventprime_event_summary($post);
    }

    $out = ['events' => $items, 'count' => count($items), 'per_page' => $per_page, 'from_timestamp' => $now];

    if ($skipped > 0) {
        $out['skipped'] = $skipped;
    }

    return $out;
}

/* ──────────────────────────────── settings ───────────────────────────────── */

/**
 * EventPrime's own settings, through the allow-list.
 *
 * `em_global_settings` is one flat object where a currency symbol sits next to a
 * PayPal client secret (includes/class-eventprime-global-settings.php:332-338), so
 * the handful of keys worth reporting are named and everything else comes back as
 * a name and whether it is set. The plugin's REST key and encryption material live
 * in their own options and are not read at all.
 */
function nibwp_eventprime_get_settings(array $input): array|WP_Error
{
    $denied = nibwp_eventprime_require(__('Reading EventPrime settings', 'nibwp'), NIBWP_EP_SETTINGS_CAP);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $filtered = nibwp_eventprime_filter_values(nibwp_eventprime_settings(), nibwp_eventprime_settings_allowed());

    return [
        'settings' => $filtered['values'],
        'withheld_settings' => $filtered['withheld'],
        'timezone' => nibwp_eventprime_site_timezone()->getName(),
        'time_format_php' => nibwp_eventprime_time_format(),
        'note' => __('Payment credentials, licence data and the plugin\'s own API key are reported as present or absent and never as values.', 'nibwp'),
    ];
}

/* ───────────────────────────────── audit ────────────────────────────────── */

/**
 * What is wrong with this calendar.
 *
 * Every finding here is something an owner cannot see from a list screen and that
 * costs money or trust when it goes unnoticed: an event that is published and
 * invisible, an event taking bookings it has no room for, a past event still
 * occupying the featured slot, a booking whose event is gone.
 */
function nibwp_eventprime_audit(array $input): array|WP_Error
{
    $denied = nibwp_eventprime_require(__('Auditing events', 'nibwp'), NIBWP_EP_EVENT_CAP);

    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $per_page = nibwp_eventprime_per_page($input);
    $now = time();

    $posts = get_posts([
        'post_type' => NIBWP_EP_EVENT_TYPE,
        'post_status' => ['publish', 'future', 'private'],
        'posts_per_page' => $per_page,
        'orderby' => 'ID',
        'order' => 'ASC',
        'suppress_filters' => false,
    ]);

    $findings = [
        'no_dates' => [],
        'end_before_start' => [],
        'no_venue' => [],
        'at_capacity_still_booking' => [],
        'past_still_featured' => [],
    ];

    $examined = 0;
    $skipped = 0;

    foreach (is_array($posts) ? $posts : [] as $post) {
        if (!$post instanceof WP_Post) {
            continue;
        }

        if (!nibwp_eventprime_can_edit($post->ID)) {
            ++$skipped;
            continue;
        }

        ++$examined;
        $row = ['id' => $post->ID, 'title' => $post->post_title];
        $start = (int) get_post_meta($post->ID, 'em_start_date_time', true);
        $end = (int) get_post_meta($post->ID, 'em_end_date_time', true);

        if (!nibwp_eventprime_dates_complete($post->ID)) {
            $findings['no_dates'][] = $row;
        } elseif ($end < $start) {
            $findings['end_before_start'][] = $row;
        }

        if ([] === nibwp_eventprime_event_term_names($post->ID, 'venue')) {
            $findings['no_venue'][] = $row;
        }

        $booking_mode = (string) get_post_meta($post->ID, 'em_enable_booking', true);

        if ('' !== $booking_mode && '0' !== $booking_mode) {
            $capacity = nibwp_eventprime_capacity_total($post->ID);
            $counts = nibwp_eventprime_count_for_event($post->ID);

            if ($capacity['known'] && $capacity['total'] > 0 && $counts['active'] >= $capacity['total']) {
                $findings['at_capacity_still_booking'][] = $row + [
                    'capacity_total' => $capacity['total'],
                    'bookings_active' => $counts['active'],
                ];
            }
        }

        if ($end > 0 && $end < $now && 1 === (int) get_post_meta($post->ID, 'em_is_featured', true)) {
            $findings['past_still_featured'][] = $row;
        }
    }

    $out = [
        'examined' => $examined,
        'findings' => $findings,
        'counts' => array_map('count', $findings),
        'per_page' => $per_page,
    ];

    if ($skipped > 0) {
        $out['skipped'] = $skipped;
        $out['skipped_note'] = __('Events this account may not edit were not examined.', 'nibwp');
    }

    // The orphan check reads bookings, so it is gated on the bookings capability
    // rather than smuggled in behind the events one.
    if (current_user_can(NIBWP_EP_BOOKING_CAP)) {
        $out['findings']['bookings_without_event'] = nibwp_eventprime_orphan_bookings($per_page);
        $out['counts']['bookings_without_event'] = count($out['findings']['bookings_without_event']);
    } else {
        $out['findings']['bookings_without_event'] = [];
        $out['withheld_findings'] = [[
            'finding' => 'bookings_without_event',
            'needs' => NIBWP_EP_BOOKING_CAP,
            'why' => __('Finding orphaned bookings means reading bookings, which this account may not do.', 'nibwp'),
        ]];
    }

    return $out;
}

/** Bookings whose em_event points at a post that is gone or was never an event. */
function nibwp_eventprime_orphan_bookings(int $limit): array
{
    global $wpdb;

    if (!is_object($wpdb)) {
        return [];
    }

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT b.ID AS booking_id, e.meta_value AS event_id'
            . ' FROM ' . $wpdb->posts . ' AS b'
            . ' INNER JOIN ' . $wpdb->postmeta . ' AS e ON e.post_id = b.ID AND e.meta_key = %s'
            . ' LEFT JOIN ' . $wpdb->posts . ' AS ev ON ev.ID = e.meta_value AND ev.post_type = %s'
            . ' WHERE b.post_type = %s AND ev.ID IS NULL LIMIT %d',
            'em_event',
            NIBWP_EP_EVENT_TYPE,
            NIBWP_EP_BOOKING_TYPE,
            $limit
        ),
        ARRAY_A
    );

    $out = [];

    foreach (is_array($rows) ? $rows : [] as $row) {
        $out[] = [
            'booking_id' => (int) ($row['booking_id'] ?? 0),
            'event_id' => (int) ($row['event_id'] ?? 0),
            'why' => __('The booking still holds attendee data but its event no longer exists.', 'nibwp'),
        ];
    }

    return $out;
}

/* ──────────────────────────── the action surface ─────────────────────────── */

/** Every action, in the vocabulary the other events integration already uses. */
function nibwp_eventprime_actions(): array
{
    return [
        'list_events',
        'get_event',
        'create_event',
        'update_event',
        'set_event_status',
        'trash_event',
        'delete_event_permanently',
        'list_venues',
        'get_venue',
        'create_venue',
        'update_venue',
        'list_event_types',
        'create_event_type',
        'list_organizers',
        'list_performers',
        'get_performer',
        'create_performer',
        'list_bookings',
        'get_booking',
        'count_bookings',
        'event_attendance',
        'event_capacity',
        'list_tickets',
        'calendar_between',
        'upcoming_events',
        'get_settings',
        'audit_events',
    ];
}

/** One arm per action, and the check asserts the enum and this list agree both ways. */
function nibwp_eventprime_dispatch(string $action, array $input): array|WP_Error
{
    return match ($action) {
        'list_events' => nibwp_eventprime_list_events($input),
        'get_event' => nibwp_eventprime_get_event($input),
        'create_event' => nibwp_eventprime_create_event($input),
        'update_event' => nibwp_eventprime_update_event($input),
        'set_event_status' => nibwp_eventprime_set_event_status($input),
        'trash_event' => nibwp_eventprime_trash_event($input),
        'delete_event_permanently' => nibwp_eventprime_delete_event($input),
        'list_venues' => nibwp_eventprime_list_terms('venue', $input),
        'get_venue' => nibwp_eventprime_get_venue($input),
        'create_venue' => nibwp_eventprime_create_term('venue', $input),
        'update_venue' => nibwp_eventprime_update_venue($input),
        'list_event_types' => nibwp_eventprime_list_terms('event_type', $input),
        'create_event_type' => nibwp_eventprime_create_term('event_type', $input),
        'list_organizers' => nibwp_eventprime_list_terms('organizer', $input),
        'list_performers' => nibwp_eventprime_list_performers($input),
        'get_performer' => nibwp_eventprime_get_performer($input),
        'create_performer' => nibwp_eventprime_create_performer($input),
        'list_bookings' => nibwp_eventprime_list_bookings($input),
        'get_booking' => nibwp_eventprime_get_booking($input),
        'count_bookings' => nibwp_eventprime_count_bookings($input),
        'event_attendance' => nibwp_eventprime_event_attendance($input),
        'event_capacity' => nibwp_eventprime_event_capacity($input),
        'list_tickets' => nibwp_eventprime_list_tickets($input),
        'calendar_between' => nibwp_eventprime_calendar_between($input),
        'upcoming_events' => nibwp_eventprime_upcoming_events($input),
        'get_settings' => nibwp_eventprime_get_settings($input),
        'audit_events' => nibwp_eventprime_audit($input),
        default => nibwp_eventprime_err('unknown_action', __('Unknown action.', 'nibwp')),
    };
}

/**
 * The hub.
 *
 * The guard answers first and with its own code, so "the plugin is missing" can
 * never be confused with "the request was wrong", and no branch below has to wonder
 * whether EventPrime is there.
 */
function nibwp_eventprime_manage(array $input): array|WP_Error
{
    $guard = nibwp_eventprime_guard();

    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $action = is_string($input['action'] ?? null) ? $input['action'] : '';

    if (!in_array($action, nibwp_eventprime_actions(), true)) {
        return nibwp_eventprime_err(
            'unknown_action',
            sprintf(
                /* translators: 1: the action requested, 2: comma separated list of valid actions */
                __('Unknown action "%1$s". This hub offers: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_eventprime_actions())
            )
        );
    }

    try {
        return nibwp_eventprime_dispatch($action, $input);
    } catch (\Throwable $error) {
        // A throw from inside EventPrime is a refusal, not a 500 that takes the
        // whole REST request with it.
        return nibwp_eventprime_err('plugin_error', $error->getMessage(), 500);
    }
}

/* ──────────────────────────── registration ──────────────────────────────── */

wp_register_ability('nibwp/eventprime-manage', [
    'label' => __('EventPrime – Management', 'nibwp'),
    'description' => __(
        'Read and manage EventPrime. Events: list_events, get_event, create_event, update_event, set_event_status, trash_event, delete_event_permanently. Venues: list_venues, get_venue, create_venue, update_venue. Event types: list_event_types, create_event_type. Organizers: list_organizers. Performers: list_performers, get_performer, create_performer. Bookings: list_bookings, get_booking, count_bookings, event_attendance. Capacity: event_capacity, list_tickets. Calendar: calendar_between, upcoming_events. Plus get_settings and audit_events, which reports what is wrong with the calendar.',
        'nibwp'
    ),
    'category' => 'events',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => nibwp_eventprime_actions(),
                'description' => 'The action to perform.',
            ],
            'event_id' => ['type' => 'integer', 'description' => 'Event post id. Checked to really be an em_event before any permission is considered.'],
            'booking_id' => ['type' => 'integer', 'description' => 'Booking post id (em_booking).'],
            'performer_id' => ['type' => 'integer', 'description' => 'Performer post id (em_performer).'],
            'venue_id' => ['type' => 'integer', 'description' => 'Venue TERM id. A venue is a taxonomy term in EventPrime, not a post.'],
            'event_data' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string', 'description' => 'Goes to post_content.'],
                    'excerpt' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'description' => 'publish, draft, pending, private or future. New events default to draft.'],
                    'start_date' => ['type' => 'string', 'description' => 'Event-local start. Y-m-d H:i:s, Y-m-d H:i or Y-m-d.'],
                    'end_date' => ['type' => 'string', 'description' => 'Event-local end, same formats. Defaults to two hours after the start.'],
                    'timezone' => ['type' => 'string', 'description' => 'Olson name the dates are written in. Defaults to the site timezone, which is the only one EventPrime stores.'],
                    'all_day' => ['type' => 'boolean', 'description' => 'Forces the end onto the start date and the times onto the day bounds, as EventPrime does.'],
                    'venue_id' => ['type' => 'integer', 'description' => 'Venue term id.'],
                    'event_type_id' => ['type' => 'integer', 'description' => 'Event type term id.'],
                    'organizer_id' => ['type' => 'integer', 'description' => 'Organizer term id.'],
                ],
                'description' => 'Event fields for create_event and update_event.',
            ],
            'name' => ['type' => 'string', 'description' => 'Name for create_venue, create_event_type, create_organizer, create_performer and update_venue.'],
            'description' => ['type' => 'string', 'description' => 'Description for the same creates.'],
            'meta' => ['type' => 'object', 'description' => 'Allow-listed term meta for create_venue and update_venue: em_locality, em_state, em_country, em_postal_code, em_lat, em_lng and the rest of the address set. Anything else is ignored.'],
            'status' => ['type' => 'string', 'description' => 'Post status filter for listings, or the new status for set_event_status.'],
            'booking_status' => ['type' => 'string', 'description' => 'completed, pending, cancelled or refunded.'],
            'from_date' => ['type' => 'string', 'description' => 'Range start. Y-m-d H:i:s, Y-m-d H:i or Y-m-d.'],
            'to_date' => ['type' => 'string', 'description' => 'Range end, same formats. A bare date means the whole of that day.'],
            'timezone' => ['type' => 'string', 'description' => 'Timezone the range dates are written in.'],
            'search' => ['type' => 'string', 'description' => 'Name search for venue, event type and organizer listings.'],
            'confirm' => ['type' => 'boolean', 'description' => 'Required true by delete_event_permanently and by nothing else.'],
            'per_page' => ['type' => 'integer', 'default' => NIBWP_EP_DEFAULT_ROWS, 'description' => 'Clamped to ' . NIBWP_EP_MAX_ROWS . '.'],
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
            'terms' => ['type' => 'array'],
            'venue' => ['type' => 'object'],
            'performers' => ['type' => 'array'],
            'performer' => ['type' => 'object'],
            'bookings' => ['type' => 'array'],
            'booking' => ['type' => 'object'],
            'tickets' => ['type' => 'array'],
            'by_day' => ['type' => 'object'],
            'by_status' => ['type' => 'object'],
            'findings' => ['type' => 'object'],
            'settings' => ['type' => 'object'],
            'date_meta_written' => ['type' => 'object'],
            'count' => ['type' => 'integer'],
            'total' => ['type' => 'integer'],
            'skipped' => ['type' => 'integer'],
            'skipped_note' => ['type' => 'string'],
            'withheld_meta' => ['type' => 'array'],
            'withheld_settings' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => 'nibwp_eventprime_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Read and manage EventPrime: events, venues, event types, organizers, performers, bookings, tickets and capacity.',
                '',
                'THE SHAPE OF THIS PLUGIN IS UNUSUAL, and assuming otherwise wastes a whole conversation.',
                '- Events, performers and bookings are POSTS: em_event, em_performer, em_booking.',
                '- Venues, event types and organizers are TAXONOMY TERMS on em_event: em_venue, em_event_type, em_event_organizer. venue_id is a TERM id. There is no venue post to get.',
                '- Tickets are rows in EventPrime\'s own price-options table, not posts, and capacity lives per ticket row rather than per event.',
                '',
                'EVENTS:',
                '- list_events: events in a date range with a status filter. from_date/to_date/status/timezone, per_page, page.',
                '- get_event: one event in full with its dates, venue, types and organizers. Requires event_id.',
                '- create_event: needs event_data.title and event_data.start_date. Starts as a DRAFT unless event_data.status says otherwise.',
                '- update_event: requires event_id and event_data.',
                '- set_event_status: publish, draft, pending, private, future. Refuses to publish an event whose date set is incomplete, because that event would be live and invisible.',
                '- trash_event: reversible, and the bookings are untouched. Prefer this.',
                '- delete_event_permanently: IRREVERSIBLE, and it ORPHANS EVERY BOOKING FOR THE EVENT. The booking posts survive holding attendee data, but nothing in EventPrime can say what they were bookings for, and the attendee list for that event is gone. It requires confirm true and it reports how many bookings it orphaned. Ask the owner first.',
                '',
                'VENUES / TYPES / ORGANIZERS: list_venues, get_venue, create_venue, update_venue, list_event_types, create_event_type, list_organizers. Address details are allow-listed term meta on a venue.',
                'PERFORMERS: list_performers, get_performer, create_performer.',
                'BOOKINGS: list_bookings (bounded, filter by event_id and booking_status), get_booking, count_bookings (counts by status over a period), event_attendance (expected numbers for one event).',
                'CAPACITY: event_capacity (summed ticket capacity against active bookings, plus whether the event is at capacity and still taking bookings), list_tickets.',
                'CALENDAR: calendar_between (needs from_date and to_date, groups by day using the same comparison EventPrime\'s own calendar uses), upcoming_events.',
                'SETTINGS: get_settings reports the EventPrime configuration — currency, time format, booking defaults — through an allow-list.',
                'AUDIT: audit_events reports events with no date set or an end before the start, events with no venue, events at capacity still taking bookings, past events still featured, and bookings whose event no longer exists.',
                '',
                'DATES: send event-local time, optionally with a timezone name. Accepted formats are Y-m-d H:i:s, Y-m-d\\TH:i:s, Y-m-d H:i, Y-m-d\\TH:i and Y-m-d, and nothing else — "next Friday" or "15/06/2026" is refused rather than guessed. An end before its start is refused, not reordered. EventPrime stores an event as four integer timestamps plus two display strings plus an all-day flag, and they are all written together: miss any one and the event exists in wp-admin and appears nowhere else, because every query in the plugin inner-joins on em_start_date_time and compares em_start_date numerically.',
                '',
                'IDS: an id is checked to really be of the expected post type, or a term id of the expected taxonomy, BEFORE any permission is considered. Handing an event action the id of a page refuses on the type and writes nothing.',
                '',
                'PERMISSIONS: events take edit_em_events plus edit_post for the one event. BOOKINGS TAKE edit_em_bookings, which is the capability EventPrime\'s own bookings screen is reached with and which only the administrator role has by default — not edit_posts. Settings take manage_options. A listing that quietly contained fewer rows would be a lie, so it reports skipped and skipped_note.',
                '',
                'PERSONAL DATA: a booking holds attendee names and whatever the checkout form asked. Those never leave this hub. Counts, statuses and attendee NUMBERS do.',
                '',
                'CREDENTIALS: every value passes an allow-list. PayPal keys, licence data, the plugin\'s REST key, order records, payment logs and booking order keys come back as a name with value_returned false under withheld_meta or withheld_settings, and a key that is allowed by name but holds an array is dropped rather than flattened.',
                '',
                'NOT HERE AND NOT COMING: cancelling or refunding a booking somebody paid for. The plugin can, and a wrong guess is a chargeback. Those two belong to a person in wp-admin with the order in front of them.',
                '',
                'REQUIRES: EventPrime must be active.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);
