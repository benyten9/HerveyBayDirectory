<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * WooCommerce Pro — nibwp/wcpro-analytics.
 *
 * The numbers, with their provenance attached.
 *
 * A WooCommerce store can answer "what did I make last month" from two different
 * places, and they are not the same place:
 *
 *   1. WooCommerce Admin's analytics tables — wc_order_stats,
 *      wc_order_product_lookup, wc_order_coupon_lookup, wc_customer_lookup.
 *      Indexed, fast, and what the Analytics screens in wp-admin show. They are a
 *      DERIVED copy, filled by a scheduled import, so on a store that has just
 *      installed WooCommerce Admin, just imported orders, or just had its lookup
 *      tables rebuilt, they are BEHIND the order book — sometimes by months.
 *   2. The order book itself, read live. Always current, never indexed for this,
 *      so it can only ever be read in a bounded window.
 *
 * A store mid-import will disagree with itself, and the two numbers look equally
 * authoritative in a JSON response. So every response here carries `source`, and
 * it is one of exactly two values: "analytics_tables" or "live_query". That key is
 * not decoration. An owner who reads "revenue 12,480" and acts on it needs to know
 * whether that is the money or a cache of the money.
 *
 * Where a figure can come from both, this hub prefers the analytics tables — they
 * are what the owner sees in wp-admin, so they are the number a conversation gets
 * compared against — and it cross-checks them against a bounded live count on
 * request, or whenever the import is known to be running. It does not reconcile a
 * disagreement. It reports both figures, the gap, and the fact that they disagree,
 * because picking one quietly is how the wrong number gets acted on.
 *
 * The other rule here is time. "Last 30 days" resolved against the server's UTC
 * clock, on a store in Auckland, is a different month from the one the owner means.
 * Every period is resolved in the SITE's timezone, and every response that reports
 * a period states the range it actually used, in that timezone, with the offset
 * written out.
 *
 * Reading revenue and customer acquisition is reading the business, so the whole
 * hub asks for manage_woocommerce. Where a response names a specific product or
 * coupon, that object is asked about as well, and a record the account may not read
 * is counted as skipped rather than folded into a total.
 */

require_once __DIR__ . '/../lib/guard.php';
require_once __DIR__ . '/../lib/sanitize.php';

/**
 * The action enum. Single source of truth — the registration and
 * tests/skills/woocommerce-pro-check.php both read this, and the switch in
 * nibwp_wcpro_analytics_execute() must match it exactly in both directions.
 *
 * @return array<int, string>
 */
function nibwp_wcpro_analytics_actions(): array
{
    return [
        // Money over a period, against the period before it.
        'revenue_summary',
        'refund_rate',
        // What sold, and what cannot be sold.
        'top_sellers',
        'low_stock',
        // What the discounts cost.
        'coupon_performance',
        // Who arrived.
        'customer_acquisition',
        // The handful of numbers an owner acts on, in one response.
        'store_health',
    ];
}

/**
 * The only two answers `source` is ever allowed to carry.
 *
 * Two, not three, and never blank. A response whose figure came from somewhere
 * else has no business existing: the point of the key is that a caller can tell
 * the order book from a cache of it without reading prose.
 *
 * @return array<int, string>
 */
function nibwp_wcpro_analytics_sources(): array
{
    return ['analytics_tables', 'live_query'];
}

/**
 * Every bound in this hub, with the ceiling no input can raise it past.
 *
 * A table rather than magic numbers in nine places, because the ceilings are the
 * whole reason an analytics call on a large store answers at all, and a ceiling
 * that lives at its call site is one somebody edits in one place and not the other
 * eight. nibwp_wcpro_analytics_bound() is the only reader of caller-supplied
 * bounds in this file.
 *
 * @return array<string, array{default: int, min: int, max: int}>
 */
function nibwp_wcpro_analytics_bounds(): array
{
    return [
        // Rows of a leaderboard. Fifty products is already more than anybody reads.
        'limit'        => ['default' => 10, 'min' => 1, 'max' => 50],
        // Products in a stock list. Each row costs a product read.
        'stock_limit'  => ['default' => 50, 'min' => 1, 'max' => 200],
        // Coupons. A store with more than a hundred live coupons has another problem.
        'coupon_limit' => ['default' => 20, 'min' => 1, 'max' => 100],
        // Orders hydrated by a live read. Every one is an object build, so this is
        // the ceiling that decides whether the request finishes at all.
        'scan_limit'   => ['default' => 500, 'min' => 1, 'max' => 2000],
        // Buckets on a time series. Past two dozen nobody reads the shape.
        'buckets'      => ['default' => 12, 'min' => 1, 'max' => 24],
        // A low-stock threshold. Zero is meaningful — it means "only what is gone".
        'threshold'    => ['default' => 2, 'min' => 0, 'max' => 1000],
        // The longest span any period may cover, in days. Two years.
        'max_days'     => ['default' => 30, 'min' => 1, 'max' => 730],
    ];
}

/**
 * Resolve one caller-supplied bound against the table.
 *
 * The maximum is looked up here and cannot be passed in, which is the point: a
 * helper that took its own ceiling as an argument would let a call site raise it,
 * and then "max 2000" would be true of the helper and false of the hub.
 */
function nibwp_wcpro_analytics_bound(array $input, string $key): int
{
    $bounds = nibwp_wcpro_analytics_bounds();
    if (!isset($bounds[$key])) {
        // An unknown bound is a programming mistake, not a caller mistake. Answer
        // with the tightest window there is rather than an unbounded one.
        return 1;
    }

    $default = (int) $bounds[$key]['default'];
    $min = (int) $bounds[$key]['min'];
    $max = (int) $bounds[$key]['max'];

    $raw = $input[$key] ?? null;
    if ($raw === null || is_array($raw) || !is_numeric($raw)) {
        return $default;
    }

    return min(max((int) $raw, $min), $max);
}

nibwp_wcpro_register_hub(
    'nibwp/wcpro-analytics',
    __('WooCommerce Pro — store figures, with their provenance', 'nibwp'),
    'The numbers an owner acts on, each one saying where it came from: revenue over a period against the period before it, the refund rate, top sellers, low-stock and out-of-stock products, coupon performance, new customers over time, and a one-call store health summary. Every response carries "source": "analytics_tables" (WooCommerce Admin own report data stores — what wp-admin Analytics shows, and a derived copy that LAGS behind the order book until its scheduled import runs) or "live_query" (the order book itself, read in a bounded window). Every period is resolved in the SITE timezone and the resolved range is stated in the response. Nothing here writes. For one order, product or customer use the Free nibwp/wc-* abilities; for a quick sales or top-seller total with no provenance and no comparison, the Free nibwp/wc-get-reports is cheaper.',
    nibwp_wcpro_analytics_actions(),
    [
        'period' => [
            'type' => 'string',
            'enum' => ['today', 'yesterday', 'last_7_days', 'last_30_days', 'last_90_days', 'last_365_days', 'this_month', 'last_month', 'this_year', 'custom'],
            'default' => 'last_30_days',
            'description' => 'Which period, resolved in the SITE timezone. last_N_days means N WHOLE days ending yesterday — today is left out, because a partial day compared against a whole one flatters the comparison. Use "today", "this_month" or "this_year" when the partial current period is what you want; the response flags it as partial.',
        ],
        'date_from' => ['type' => 'string', 'description' => 'For period:"custom" — YYYY-MM-DD, inclusive, read as 00:00:00 in the site timezone.'],
        'date_to' => ['type' => 'string', 'description' => 'For period:"custom" — YYYY-MM-DD, inclusive, read as 23:59:59 in the site timezone. A date in the future is clamped to now and the period is reported as partial.'],
        'compare' => ['type' => 'boolean', 'default' => true, 'description' => 'For revenue_summary and refund_rate: also read the previous period of the same length. The response names the comparison basis it used.'],
        'verify' => ['type' => 'boolean', 'default' => false, 'description' => 'Cross-check an analytics-sourced figure against a bounded live count of the same window. Costs a second read. Forced on when WooCommerce Admin reports an import in progress, because that is exactly when the two disagree.'],
        'orderby' => ['type' => 'string', 'enum' => ['items_sold', 'net_revenue'], 'default' => 'items_sold', 'description' => 'For top_sellers: rank by units moved or by money taken. They are different lists, and the cheap product is usually top of the first.'],
        'limit' => ['type' => 'integer', 'default' => 10, 'description' => 'For top_sellers: rows. Max 50.'],
        'stock_limit' => ['type' => 'integer', 'default' => 50, 'description' => 'For low_stock: products per list. Max 200.'],
        'coupon_limit' => ['type' => 'integer', 'default' => 20, 'description' => 'For coupon_performance: coupons. Max 100.'],
        'threshold' => ['type' => 'integer', 'description' => 'For low_stock: count a product low at or below this quantity. Left out, the store own low-stock setting is used, and a product own override wins where it has one. Max 1000.'],
        'buckets' => ['type' => 'integer', 'default' => 12, 'description' => 'For customer_acquisition: how many time buckets to split the period into. Max 24.'],
        'scan_limit' => ['type' => 'integer', 'default' => 500, 'description' => 'How many orders a live read may hydrate. Max 2000. The response says how many were scanned and whether the window was full — a figure over a full window is a floor, not a total.'],
        'include_unused' => ['type' => 'boolean', 'default' => true, 'description' => 'For coupon_performance: also list published coupons used zero times in the period. That list is usually the point.'],
    ],
    'nibwp_wcpro_analytics_execute',
    implode("\n", [
        'Read "source" on every response before quoting a number. "analytics_tables" is WooCommerce own report data — the figures wp-admin Analytics shows — and it is a DERIVED copy filled by a scheduled import, so it can be behind the order book. "live_query" is the order book itself, read in a bounded window. Say which one you are quoting; a figure without its provenance is how the wrong number gets acted on.',
        'Every response that reports a period carries period.resolved — the exact range used, in the site timezone, with the UTC offset. Quote that range rather than the words the user asked with: "last 30 days" resolved on a UTC server for a store in Auckland is a different month.',
        'revenue_summary { period } is gross sales, net revenue, orders, items, average order value, tax, shipping and discounts, with the previous period of the same length beside it and the change in absolute and percentage terms. period.comparison_basis says how the previous period was chosen.',
        'verify:true on revenue_summary or store_health reads the same window a second time from the order book and reports both figures. When they disagree the response sets disputed:true and reports BOTH — it does not reconcile them. The usual cause is the analytics import still running; say so and offer to re-run rather than picking a number.',
        'refund_rate { period } is refunds as a share of gross sales and of order count, against the previous period. A refund rate that moved is a product problem or a fulfilment problem, and top_sellers over the same window decides which.',
        'top_sellers { period, orderby } ranks by items_sold or net_revenue. They are different lists. Each row carries the product id, so the Free nibwp/wc-get-product reads the rest.',
        'low_stock { threshold } is ALWAYS live_query — stock has no analytics table, it is the product record, so this figure is never stale. It returns two lists: at or below the threshold, and out of stock entirely. Product variations are not walked, so a variable product shows its parent-level stock only.',
        'coupon_performance { period } is orders and discount given per coupon, plus the published coupons used zero times. A code seen in an order whose coupon has since been deleted is reported separately rather than dropped.',
        'customer_acquisition { period, buckets } is new customers per bucket across the period. It counts REGISTRATIONS, not first orders, and it says so — a store with guest checkout acquires customers it never registers.',
        'store_health { period } is one call for the numbers an owner acts on: revenue and its direction, refund rate, out-of-stock count, low-stock count, the top seller, new customers. Each figure carries its own source, and mixed_sources:true says the response drew on both. Start here.',
        'Nothing in this hub writes. Every query is bounded and every bound has a ceiling no input can raise. When a live read filled its window, window_full is true and the figure is a FLOOR — report it as one, or narrow the period.',
        'The Free nibwp/wc-get-reports gives a quick sales or top-seller total with no provenance, no comparison period and no timezone resolution. It is cheaper; use it when none of those matter. Do not use it to answer "is this number current?" — it cannot.',
    ]),
    // Every action here reads; none can change anything.
    true,
);

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_analytics_execute(array $input): array|WP_Error
{
    $guard = nibwp_wcpro_guard($input, false);
    if (is_wp_error($guard)) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');

    // One bar for the whole hub, and it is the high one. Revenue, refunds and
    // customer acquisition are the business rather than a setting, and an
    // aggregate over the whole store has no object to ask about. Where a response
    // names a specific product or coupon, that object is asked about too, below.
    $denied = nibwp_wcpro_require_cap('manage_woocommerce', 'Reading the store revenue, refund and customer figures');
    if ($denied !== null) {
        return $denied;
    }

    switch ($action) {
        case 'revenue_summary':
            return nibwp_wcpro_analytics_revenue($input);

        case 'refund_rate':
            return nibwp_wcpro_analytics_refunds($input);

        case 'top_sellers':
            return nibwp_wcpro_analytics_top_sellers($input);

        case 'low_stock':
            return nibwp_wcpro_analytics_low_stock($input);

        case 'coupon_performance':
            return nibwp_wcpro_analytics_coupons($input);

        case 'customer_acquisition':
            return nibwp_wcpro_analytics_acquisition($input);

        case 'store_health':
            return nibwp_wcpro_analytics_store_health($input);
    }

    return nibwp_wcpro_unknown_action($action, nibwp_wcpro_analytics_actions());
}

/* ------------------------------------------------------------------ the clock */

/**
 * The timezone the STORE keeps its books in.
 *
 * wp_timezone() is the right answer and exists from WordPress 5.3, but the
 * fallbacks matter: a site that never set timezone_string has only a numeric
 * gmt_offset, and treating that as UTC silently moves every boundary by hours. A
 * DateTimeZone constructor throws on a value it does not recognise, so each step
 * is attempted rather than trusted, and UTC is the last resort rather than the
 * first assumption.
 */
function nibwp_wcpro_analytics_timezone(): DateTimeZone
{
    if (function_exists('wp_timezone')) {
        try {
            $zone = wp_timezone();
            if ($zone instanceof DateTimeZone) {
                return $zone;
            }
        } catch (Throwable $e) {
            // Fall through to the option rows.
        }
    }

    if (function_exists('wc_timezone_string')) {
        try {
            return new DateTimeZone((string) wc_timezone_string());
        } catch (Throwable $e) {
            // Fall through.
        }
    }

    $named = trim((string) get_option('timezone_string', ''));
    if ($named !== '') {
        try {
            return new DateTimeZone($named);
        } catch (Throwable $e) {
            // Fall through to the offset.
        }
    }

    // A numeric offset, which is what a site that only ever used the dropdown has.
    // Rendered as +HH:MM because DateTimeZone will not take a float.
    $offset = (float) get_option('gmt_offset', 0);
    $sign = $offset < 0 ? '-' : '+';
    $absolute = abs($offset);
    $hours = (int) floor($absolute);
    $minutes = (int) round(($absolute - $hours) * 60);

    try {
        return new DateTimeZone(sprintf('%s%02d:%02d', $sign, $hours, $minutes));
    } catch (Throwable $e) {
        return new DateTimeZone('UTC');
    }
}

/**
 * Both ends of a window, in every spelling the rest of this file needs.
 *
 * The timestamps are what the order queries are given, because an order date query
 * handed a bare date string is interpreted by whatever timezone the data store
 * feels like and that is the bug this whole helper exists to stop.
 *
 * @return array<string, mixed>
 */
function nibwp_wcpro_analytics_window(DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $seconds = max(0, $to->getTimestamp() - $from->getTimestamp());

    return [
        'from'           => $from->format('Y-m-d H:i:s'),
        'to'             => $to->format('Y-m-d H:i:s'),
        'from_utc'       => $from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        'to_utc'         => $to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        'from_timestamp' => $from->getTimestamp(),
        'to_timestamp'   => $to->getTimestamp(),
        // Calendar dates, inclusive, not elapsed seconds over 86400: a window that
        // crosses a clock change holds a 23- or 25-hour day, and dividing seconds
        // turned 30 days into 31 for a month after every autumn change. A partial
        // day still counts as a day the figure covers.
        'days'           => (int) max(1, (int) $from->setTime(0, 0, 0)->diff($to->setTime(0, 0, 0))->days + 1),
    ];
}

/**
 * The period a request is about, resolved in the site timezone.
 *
 * Two decisions worth stating, because both change the number:
 *
 *   1. last_N_days is N WHOLE days ending YESTERDAY. Including today would compare
 *      N-1 whole days plus a few hours against N whole days, which makes every
 *      period look worse than the one before it for no reason but the clock. Ask
 *      for period:"today" or "this_month" when the partial current period is the
 *      point; those are flagged partial.
 *   2. The previous period is chosen by the SHAPE of the current one. A
 *      month-to-date is compared against the same number of days of the previous
 *      month, not against the whole of it; a whole calendar month is compared
 *      against the whole previous one. comparison_basis says which rule ran.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_analytics_period(array $input): array|WP_Error
{
    $zone = nibwp_wcpro_analytics_timezone();
    $period = nibwp_wcpro_enum(
        $input['period'] ?? 'last_30_days',
        ['today', 'yesterday', 'last_7_days', 'last_30_days', 'last_90_days', 'last_365_days', 'this_month', 'last_month', 'this_year', 'custom'],
        'period',
        'last_30_days',
    );
    if (is_wp_error($period)) {
        return $period;
    }

    try {
        $now = new DateTimeImmutable('now', $zone);
    } catch (Throwable $e) {
        return nibwp_wcpro_err('clock_unavailable', 'The site timezone could not be resolved, so no period can be stated honestly.', 500);
    }

    $today = $now->setTime(0, 0, 0);
    $partial = false;
    $note = '';

    switch ($period) {
        case 'today':
            $from = $today;
            $to = $now;
            $partial = true;
            $note = 'Today so far, which is a partial day.';
            break;

        case 'yesterday':
            $from = $today->modify('-1 day');
            $to = $from->setTime(23, 59, 59);
            $note = 'Yesterday, one whole day.';
            break;

        case 'this_month':
            $from = $today->modify('first day of this month');
            $to = $now;
            $partial = true;
            $note = 'This calendar month so far, which is a partial month.';
            break;

        case 'last_month':
            $from = $today->modify('first day of last month');
            $to = $from->modify('last day of this month')->setTime(23, 59, 59);
            $note = 'The whole of last calendar month.';
            break;

        case 'this_year':
            $from = $today->modify('first day of January this year');
            $to = $now;
            $partial = true;
            $note = 'This calendar year so far, which is a partial year.';
            break;

        case 'custom':
            $resolved = nibwp_wcpro_analytics_custom_range($input, $zone, $now);
            if (is_wp_error($resolved)) {
                return $resolved;
            }
            $from = $resolved['from'];
            $to = $resolved['to'];
            $partial = (bool) $resolved['partial'];
            $note = (string) $resolved['note'];
            break;

        default:
            // last_7_days / last_30_days / last_90_days / last_365_days.
            $days = (int) str_replace(['last_', '_days'], '', $period);
            $to = $today->modify('-1 day')->setTime(23, 59, 59);
            $from = $to->setTime(0, 0, 0)->modify(sprintf('-%d days', $days - 1));
            $note = sprintf('%d whole days ending yesterday; today is left out so the comparison is like for like.', $days);
            break;
    }

    if ($from > $to) {
        return nibwp_wcpro_err('impossible_period', 'The period starts after it ends, so nothing can be reported for it.', 422);
    }

    $window = nibwp_wcpro_analytics_window($from, $to);
    $max_days = (int) nibwp_wcpro_analytics_bounds()['max_days']['max'];
    if ($window['days'] > $max_days) {
        return nibwp_wcpro_err(
            'period_too_long',
            sprintf(
                'That period covers %d days and this hub reads at most %d. Ask for a shorter range, or read the years one at a time — a two-year live scan does not finish.',
                (int) $window['days'],
                $max_days,
            ),
            422,
        );
    }

    $previous = nibwp_wcpro_analytics_previous_window($from, $to, $period, $zone);

    $resolved = sprintf(
        '%s to %s in the site timezone %s (UTC%s). %s',
        (string) $window['from'],
        (string) $window['to'],
        $zone->getName(),
        $now->format('P'),
        $note,
    );

    return $window + [
        'period'           => $period,
        'timezone'         => $zone->getName(),
        'utc_offset'       => $now->format('P'),
        'partial'          => $partial,
        'resolved'         => $resolved,
        'comparison_basis' => (string) $previous['basis'],
        'previous'         => nibwp_wcpro_analytics_window($previous['from'], $previous['to']) + [
            'comparison_basis' => (string) $previous['basis'],
        ],
    ];
}

/**
 * A caller-supplied range.
 *
 * Clamped at the end rather than refused when it runs past now: a user asking for
 * "1 September to 31 December" in October means "everything so far", and refusing
 * that is unhelpful — but reporting it as a whole four months without saying it is
 * three and a bit would be a lie.
 *
 * @return array{from: DateTimeImmutable, to: DateTimeImmutable, partial: bool, note: string}|WP_Error
 */
function nibwp_wcpro_analytics_custom_range(array $input, DateTimeZone $zone, DateTimeImmutable $now): array|WP_Error
{
    $raw_from = nibwp_wcpro_date($input['date_from'] ?? '', 'date_from');
    if (is_wp_error($raw_from)) {
        return $raw_from;
    }
    $raw_to = nibwp_wcpro_date($input['date_to'] ?? '', 'date_to');
    if (is_wp_error($raw_to)) {
        return $raw_to;
    }
    if ($raw_from === '' || $raw_to === '') {
        return nibwp_wcpro_err('missing_dates', 'period:"custom" needs date_from and date_to as YYYY-MM-DD. They are read in the site timezone.', 422);
    }

    try {
        $from = (new DateTimeImmutable(substr($raw_from, 0, 10), $zone))->setTime(0, 0, 0);
        $to = (new DateTimeImmutable(substr($raw_to, 0, 10), $zone))->setTime(23, 59, 59);
    } catch (Throwable $e) {
        return nibwp_wcpro_err('invalid_date', 'date_from and date_to must be real dates in YYYY-MM-DD form.', 422);
    }

    $partial = false;
    $note = 'The range you asked for, read in the site timezone.';
    if ($to > $now) {
        $to = $now;
        $partial = true;
        $note = 'The range you asked for, clipped at the current time — the end date is in the future, so this period is partial.';
    }

    return ['from' => $from, 'to' => $to, 'partial' => $partial, 'note' => $note];
}

/**
 * The period to compare against, chosen by the shape of the current one.
 *
 * Arithmetic is done on elapsed seconds rather than on calendar units, so a
 * daylight-saving change inside a window shifts a boundary by an hour. That is
 * below the noise of any figure in this hub and it is the honest trade against
 * pretending a 23-hour day was 24.
 *
 * @return array{from: DateTimeImmutable, to: DateTimeImmutable, basis: string}
 */
function nibwp_wcpro_analytics_previous_window(DateTimeImmutable $from, DateTimeImmutable $to, string $period, DateTimeZone $zone): array
{
    $elapsed = max(0, $to->getTimestamp() - $from->getTimestamp());

    if ($period === 'this_month') {
        // Month to date against the same slice of last month, clipped to its end,
        // because comparing 17 days against a whole 31 is not a comparison.
        $start = $from->modify('first day of last month')->setTime(0, 0, 0);
        $end = $start->modify(sprintf('+%d seconds', $elapsed));
        $month_end = $start->modify('last day of this month')->setTime(23, 59, 59);

        return ['from' => $start, 'to' => min($end, $month_end), 'basis' => 'previous_month_to_date'];
    }

    if ($period === 'this_year') {
        $start = $from->modify('-1 year')->setTime(0, 0, 0);

        return ['from' => $start, 'to' => $start->modify(sprintf('+%d seconds', $elapsed)), 'basis' => 'previous_year_to_date'];
    }

    if ($period === 'last_month') {
        $start = $from->modify('first day of last month')->setTime(0, 0, 0);
        $end = $start->modify('last day of this month')->setTime(23, 59, 59);

        return ['from' => $start, 'to' => $end, 'basis' => 'previous_calendar_month'];
    }

    if ($period === 'today') {
        $start = $from->modify('-1 day');

        return ['from' => $start, 'to' => $start->modify(sprintf('+%d seconds', $elapsed)), 'basis' => 'yesterday_to_the_same_time'];
    }

    // Everything else: the same span, immediately before, ending one second before
    // this period starts.
    $end = $from->modify('-1 second');

    // A window made of whole days is matched with whole days rather than with an
    // equal number of seconds. Across a daylight-saving change the two differ by an
    // hour, and "30 days against 30 days minus an hour" is the version that shows up
    // in a response as a previous period starting at 01:00 for no reason a reader
    // can see. A partial window — a custom range clipped at now — is still matched
    // on elapsed time, because there the elapsed length IS the thing being compared.
    if ($from->format('H:i:s') === '00:00:00' && $to->format('H:i:s') === '23:59:59') {
        $days = (int) max(1, (int) $from->diff($to->setTime(0, 0, 0))->days + 1);

        return [
            'from'  => $end->setTime(0, 0, 0)->modify(sprintf('-%d days', $days - 1)),
            'to'    => $end,
            'basis' => 'previous_equal_length',
        ];
    }

    return ['from' => $end->modify(sprintf('-%d seconds', $elapsed)), 'to' => $end, 'basis' => 'previous_equal_length'];
}

/**
 * Split a window into buckets for a time series.
 *
 * The bucket count is a bound, so a long period gets wider buckets rather than
 * more of them. The last bucket is closed at the window end rather than running
 * past it, because a bucket that includes tomorrow reports a fall that has not
 * happened.
 *
 * @return array<int, array{label: string, from: int, to: int, from_date: string, to_date: string}>
 */
function nibwp_wcpro_analytics_buckets(array $window, int $count, DateTimeZone $zone): array
{
    $from = (int) $window['from_timestamp'];
    $to = (int) $window['to_timestamp'];
    $span = max(1, $to - $from + 1);
    $count = max(1, $count);
    $size = (int) ceil($span / $count);

    $buckets = [];
    for ($start = $from; $start <= $to; $start += $size) {
        $end = min($to, $start + $size - 1);
        $buckets[] = [
            'label'     => nibwp_wcpro_analytics_stamp($start, $zone, 'Y-m-d'),
            'from'      => $start,
            'to'        => $end,
            'from_date' => nibwp_wcpro_analytics_stamp($start, $zone, 'Y-m-d H:i:s'),
            'to_date'   => nibwp_wcpro_analytics_stamp($end, $zone, 'Y-m-d H:i:s'),
        ];
    }

    return $buckets;
}

/** A timestamp rendered in the site timezone, never in the server's. */
function nibwp_wcpro_analytics_stamp(int $timestamp, DateTimeZone $zone, string $format): string
{
    try {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($zone)->format($format);
    } catch (Throwable $e) {
        return '';
    }
}

/* ------------------------------------------------------------- provenance */

/**
 * The block every response opens with.
 *
 * `source` is top level and single-valued on purpose: a caller reading a response
 * has to be able to branch on one key. Where a response mixes the two — store_health
 * does — `source` names where the HEADLINE figures came from, every nested figure
 * carries its own, and mixed_sources says not to assume they agree.
 *
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function nibwp_wcpro_analytics_provenance(string $source, string $why, array $extra = []): array
{
    if (!in_array($source, nibwp_wcpro_analytics_sources(), true)) {
        // Unreachable while every call site passes one of the two constants, and
        // cheap insurance against the one thing this hub must never emit: a figure
        // whose provenance is a typo.
        $source = 'live_query';
        $why = 'The source could not be named, so this is reported as a live read — the weaker claim of the two.';
    }

    $import = nibwp_wcpro_analytics_import_state();

    return [
        'source' => $source,
        'provenance' => [
            'source'  => $source,
            'why'     => $why,
            'analytics_tables_available' => nibwp_wcpro_analytics_reports_available(),
            'analytics_import_running'   => $import['importing'],
            'import_note'                => $import['note'],
            'caveat' => $source === 'analytics_tables'
                ? 'These figures come from WooCommerce own analytics tables, which are a derived copy filled by a scheduled import. They are what wp-admin Analytics shows. If that import is behind, they are behind with it — pass verify:true to read the same window from the order book as well.'
                : 'These figures were read from the order book itself, in a bounded window, so they are current. They may differ from the wp-admin Analytics screens, which read the analytics tables.',
        ] + $extra,
    ];
}

/** The fully-qualified name of one WooCommerce Admin report query class. */
function nibwp_wcpro_analytics_report_class(string $suffix): string
{
    return 'Automattic\\WooCommerce\\Admin\\API\\Reports\\' . $suffix;
}

/**
 * Is WooCommerce Admin's reporting layer present at all?
 *
 * Asked about the orders-stats query specifically rather than about WooCommerce
 * Admin as a package, because the package has been merged into WooCommerce core,
 * split out again, and had these classes move namespace at least twice. What
 * matters is whether the class this hub would instantiate exists.
 */
function nibwp_wcpro_analytics_reports_available(): bool
{
    return class_exists(nibwp_wcpro_analytics_report_class('Orders\\Stats\\Query'));
}

/**
 * Whether the analytics import is running, and whether that is even knowable.
 *
 * Three answers, not two. `null` means WooCommerce would not say — an older
 * version, a scheduler that moved — and a response that reported `false` there
 * would be claiming the tables are settled when nobody checked. The distinction
 * is the difference between "these numbers are current" and "nothing says they
 * are not".
 *
 * @return array{importing: ?bool, note: string}
 */
function nibwp_wcpro_analytics_import_state(): array
{
    foreach (['Automattic\\WooCommerce\\Admin\\Schedulers\\ImportScheduler', 'Automattic\\WooCommerce\\Admin\\ReportsSync'] as $class) {
        if (!class_exists($class)) {
            continue;
        }
        try {
            if (!is_callable([$class, 'is_importing'])) {
                continue;
            }
            $importing = call_user_func([$class, 'is_importing']);
            if (is_bool($importing)) {
                return [
                    'importing' => $importing,
                    'note' => $importing
                        ? 'WooCommerce is importing orders into its analytics tables right now, so an analytics figure for a recent period is incomplete until it finishes.'
                        : 'WooCommerce reports no analytics import in progress.',
                ];
            }
        } catch (Throwable $e) {
            // A scheduler that throws is a scheduler that cannot be asked. Keep going.
        }
    }

    return [
        'importing' => null,
        'note' => 'This WooCommerce does not report whether its analytics import is running, so nothing here can promise the analytics tables are caught up.',
    ];
}

/* --------------------------------------------- the analytics tables, if present */

/** A report query's totals, which have been an array and an object across versions. */
function nibwp_wcpro_analytics_totals(object $data): array
{
    $totals = $data->totals ?? null;
    if (is_object($totals)) {
        $totals = (array) $totals;
    }

    return is_array($totals) ? $totals : [];
}

/**
 * Instantiate one WooCommerce Admin report query and hand back its data object.
 *
 * Returns `object` rather than `object|WP_Error`: a union naming both `object` and
 * a class is a fatal parse error, so the caller checks with instanceof. Null means
 * the class is absent, refused the arguments, or answered with a shape this hub
 * does not recognise — in every one of those cases the caller falls back to a live
 * read rather than guessing at the figure.
 *
 * @param array<string, mixed> $args
 */
function nibwp_wcpro_analytics_report(string $suffix, array $args): ?object
{
    $class = nibwp_wcpro_analytics_report_class($suffix);
    if (!class_exists($class)) {
        return null;
    }

    try {
        $query = new $class($args);
        if (!is_object($query) || !method_exists($query, 'get_data')) {
            return null;
        }
        $data = $query->get_data();
    } catch (Throwable $e) {
        // These classes move between WooCommerce versions and their data stores
        // throw on a lookup table that has not been created yet. A store that has
        // never run the import must get a live figure, not a stack trace.
        return null;
    }

    if ($data instanceof WP_Error || !is_object($data)) {
        return null;
    }

    return $data;
}

/**
 * The store's own figures for a window, from the analytics tables.
 *
 * Null means "could not answer" and the caller falls back. An answer of all zeroes
 * is NOT that: a store with no orders last week really did take nothing, and
 * treating an honest zero as a failure would make every quiet week read as a live
 * scan for no reason.
 *
 * @return array<string, mixed>|null
 */
function nibwp_wcpro_analytics_orders_stats(array $window): ?array
{
    $data = nibwp_wcpro_analytics_report('Orders\\Stats\\Query', [
        // Strings in the site timezone: the report data stores read an unqualified
        // date string as local time, which is the one place this hub and
        // WooCommerce already agree.
        'after'    => (string) $window['from'],
        'before'   => (string) $window['to'],
        'interval' => 'day',
        'per_page' => 1,
        'page'     => 1,
    ]);
    if ($data === null) {
        return null;
    }

    $totals = nibwp_wcpro_analytics_totals($data);
    // The two keys that decide whether this is an orders-stats payload at all. A
    // shape without them is a different report, or a version whose columns moved,
    // and either way it must not be read as revenue.
    foreach (['orders_count', 'net_revenue'] as $required) {
        if (!array_key_exists($required, $totals)) {
            return null;
        }
    }

    $gross = array_key_exists('total_sales', $totals)
        ? (float) $totals['total_sales']
        : (float) ($totals['gross_sales'] ?? 0);
    $orders = (int) $totals['orders_count'];

    return [
        'orders'      => $orders,
        'items_sold'  => (int) ($totals['num_items_sold'] ?? 0),
        'gross_sales' => $gross,
        'net_revenue' => (float) $totals['net_revenue'],
        // Signed one way in some versions and the other in others, and a refund
        // rate is a magnitude either way.
        'refunds'     => abs((float) ($totals['refunds'] ?? 0)),
        'taxes'       => (float) ($totals['taxes'] ?? 0),
        'shipping'    => (float) ($totals['shipping'] ?? 0),
        'discounts'   => abs((float) ($totals['coupons'] ?? 0)),
        'coupon_orders' => (int) ($totals['coupons_count'] ?? 0),
        'customers'   => (int) ($totals['total_customers'] ?? 0),
        'avg_order_value' => array_key_exists('avg_order_value', $totals)
            ? (float) $totals['avg_order_value']
            : ($orders > 0 ? $gross / $orders : 0.0),
        'scanned'     => $orders,
        'window_full' => false,
    ];
}

/**
 * Top products for a window, from the product lookup table.
 *
 * @return array<int, array<string, mixed>>|null
 */
function nibwp_wcpro_analytics_products_report(array $window, string $orderby, int $limit): ?array
{
    $data = nibwp_wcpro_analytics_report('Products\\Query', [
        'after'         => (string) $window['from'],
        'before'        => (string) $window['to'],
        'per_page'      => $limit,
        'page'          => 1,
        'orderby'       => $orderby,
        'order'         => 'desc',
        'extended_info' => true,
    ]);
    if ($data === null || !isset($data->data) || !is_array($data->data)) {
        return null;
    }

    $rows = [];
    foreach ($data->data as $row) {
        $row = is_object($row) ? (array) $row : (array) $row;
        $product_id = (int) ($row['product_id'] ?? 0);
        if ($product_id <= 0) {
            continue;
        }
        $extended = $row['extended_info'] ?? [];
        $extended = is_object($extended) ? (array) $extended : (array) $extended;

        $rows[] = [
            'product_id'  => $product_id,
            'name'        => (string) ($extended['name'] ?? ''),
            'sku'         => (string) ($extended['sku'] ?? ''),
            'items_sold'  => (int) ($row['items_sold'] ?? 0),
            'net_revenue' => (float) ($row['net_revenue'] ?? 0),
            'orders'      => (int) ($row['orders_count'] ?? 0),
        ];
    }

    return $rows;
}

/**
 * Coupon usage for a window, from the coupon lookup table.
 *
 * @return array<int, array<string, mixed>>|null
 */
function nibwp_wcpro_analytics_coupons_report(array $window, int $limit): ?array
{
    $data = nibwp_wcpro_analytics_report('Coupons\\Query', [
        'after'         => (string) $window['from'],
        'before'        => (string) $window['to'],
        'per_page'      => $limit,
        'page'          => 1,
        'orderby'       => 'orders_count',
        'order'         => 'desc',
        'extended_info' => true,
    ]);
    if ($data === null || !isset($data->data) || !is_array($data->data)) {
        return null;
    }

    $rows = [];
    foreach ($data->data as $row) {
        $row = (array) $row;
        $extended = $row['extended_info'] ?? [];
        $extended = (array) $extended;
        $code = strtolower(trim((string) ($extended['code'] ?? '')));
        if ($code === '') {
            continue;
        }
        $rows[] = [
            'coupon_id' => (int) ($row['coupon_id'] ?? 0),
            'code'      => $code,
            'orders'    => (int) ($row['orders_count'] ?? 0),
            'discount'  => abs((float) ($row['amount'] ?? 0)),
        ];
    }

    return $rows;
}

/**
 * How many customers registered inside one window, from the customer lookup table.
 *
 * Null rather than zero when it cannot be asked, so the caller can tell "nobody
 * signed up" from "nobody knows".
 */
function nibwp_wcpro_analytics_customers_registered(string $from, string $to): ?int
{
    $data = nibwp_wcpro_analytics_report('Customers\\Stats\\Query', [
        'registered_after'  => $from,
        'registered_before' => $to,
    ]);
    if ($data === null) {
        return null;
    }

    $totals = nibwp_wcpro_analytics_totals($data);
    if (!array_key_exists('customers_count', $totals)) {
        return null;
    }

    return (int) $totals['customers_count'];
}

/* -------------------------------------------------- the order book, bounded */

/**
 * Which order statuses count toward revenue.
 *
 * Read from the same option WooCommerce Analytics reads, because a live figure
 * computed over a different set of statuses than the analytics figure would
 * manufacture a disagreement out of nothing — and this hub treats a disagreement
 * as a signal worth flagging, so a false one is worse than useless.
 *
 * @return array<int, string>
 */
function nibwp_wcpro_analytics_counted_statuses(): array
{
    $all = [];
    if (function_exists('wc_get_order_statuses')) {
        foreach (array_keys((array) wc_get_order_statuses()) as $status) {
            $all[] = nibwp_wcpro_analytics_bare_status((string) $status);
        }
    }
    if ($all === []) {
        $all = ['completed', 'processing', 'on-hold', 'refunded'];
    }

    $excluded = get_option('woocommerce_excluded_report_order_statuses', ['pending', 'failed', 'cancelled']);
    $bare_excluded = [];
    foreach (is_array($excluded) ? $excluded : [] as $status) {
        $bare_excluded[] = nibwp_wcpro_analytics_bare_status((string) $status);
    }
    if ($bare_excluded === []) {
        $bare_excluded = ['pending', 'failed', 'cancelled'];
    }

    $counted = array_values(array_diff(array_unique($all), $bare_excluded, ['checkout-draft', 'trash']));

    return $counted === [] ? ['completed'] : $counted;
}

/**
 * A status without its wc- prefix.
 *
 * Not ltrim($status, 'wc-'): that takes a character list, so "completed" comes back
 * as "ompleted" and the status set quietly empties.
 */
function nibwp_wcpro_analytics_bare_status(string $status): string
{
    return strpos($status, 'wc-') === 0 ? substr($status, 3) : $status;
}

/**
 * A bounded read of the order book for one window.
 *
 * The date range is given as UTC timestamps rather than as date strings, because a
 * bare date handed to an order date query is resolved by the data store's own idea
 * of the day and that is precisely the ambiguity this hub exists to remove.
 *
 * @return array{orders: array<int, object>, total: int, window_full: bool}|WP_Error
 */
function nibwp_wcpro_analytics_scan_orders(array $window, int $scan_limit): array|WP_Error
{
    $denied = nibwp_wcpro_require_function('wc_get_orders', 'Reading the order book');
    if ($denied !== null) {
        return $denied;
    }

    $args = [
        'limit'        => $scan_limit,
        'status'       => nibwp_wcpro_analytics_counted_statuses(),
        'date_created' => sprintf('%d...%d', (int) $window['from_timestamp'], (int) $window['to_timestamp']),
        'orderby'      => 'date',
        'order'        => 'DESC',
        'return'       => 'objects',
        'paginate'     => true,
    ];

    try {
        $result = wc_get_orders($args);
    } catch (Throwable $e) {
        return nibwp_wcpro_err('order_query_failed', 'The order query did not complete, so no live figure can be reported for that window.', 500);
    }

    if (is_object($result) && isset($result->orders)) {
        $orders = (array) $result->orders;
        $total = (int) ($result->total ?? count($orders));
    } elseif (is_array($result)) {
        // A filter can turn paginate off; the count is then only what came back.
        $orders = $result;
        $total = count($orders);
    } else {
        $orders = [];
        $total = 0;
    }

    $clean = [];
    foreach ($orders as $order) {
        if (is_object($order) && method_exists($order, 'get_total')) {
            $clean[] = $order;
        }
    }

    return [
        'orders'      => $clean,
        'total'       => max($total, count($clean)),
        'window_full' => count($clean) >= $scan_limit && $total > count($clean),
    ];
}

/**
 * The same figures as nibwp_wcpro_analytics_orders_stats(), from the orders.
 *
 * net_revenue is computed the way the analytics tables compute it — the order total
 * less tax and less shipping — so the two can be compared at all. A response that
 * put "total including tax and shipping" next to "net revenue" and called the gap a
 * disagreement would be inventing one.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_analytics_live_totals(array $window, int $scan_limit): array|WP_Error
{
    $scan = nibwp_wcpro_analytics_scan_orders($window, $scan_limit);
    if (is_wp_error($scan)) {
        return $scan;
    }

    $gross = 0.0;
    $net = 0.0;
    $refunds = 0.0;
    $taxes = 0.0;
    $shipping = 0.0;
    $discounts = 0.0;
    $items = 0;
    $coupon_orders = 0;
    $refunded_orders = 0;
    $customers = [];

    foreach ($scan['orders'] as $order) {
        $total = (float) $order->get_total();
        $tax = method_exists($order, 'get_total_tax') ? (float) $order->get_total_tax() : 0.0;
        $ship = method_exists($order, 'get_shipping_total') ? (float) $order->get_shipping_total() : 0.0;
        $refunded = method_exists($order, 'get_total_refunded') ? (float) $order->get_total_refunded() : 0.0;

        $gross += $total;
        $taxes += $tax;
        $shipping += $ship;
        $net += $total - $tax - $ship;
        $refunds += $refunded;
        if ($refunded > 0) {
            $refunded_orders++;
        }
        if (method_exists($order, 'get_item_count')) {
            $items += (int) $order->get_item_count();
        }
        if (method_exists($order, 'get_discount_total')) {
            $discount = (float) $order->get_discount_total();
            $discounts += $discount;
            if ($discount > 0) {
                $coupon_orders++;
            }
        }

        // A guest is still a customer. Counting only registered ones would report a
        // store with guest checkout as acquiring nobody.
        $customer_id = method_exists($order, 'get_customer_id') ? (int) $order->get_customer_id() : 0;
        if ($customer_id > 0) {
            $customers['user:' . $customer_id] = true;
        } elseif (method_exists($order, 'get_billing_email')) {
            $email = strtolower(trim((string) $order->get_billing_email()));
            if ($email !== '') {
                $customers['guest:' . $email] = true;
            }
        }
    }

    $counted = count($scan['orders']);
    $net -= $refunds;

    return [
        'orders'          => $counted,
        'items_sold'      => $items,
        'gross_sales'     => round($gross, 2),
        'net_revenue'     => round($net, 2),
        'refunds'         => round($refunds, 2),
        'refunded_orders' => $refunded_orders,
        'taxes'           => round($taxes, 2),
        'shipping'        => round($shipping, 2),
        'discounts'       => round($discounts, 2),
        'coupon_orders'   => $coupon_orders,
        'customers'       => count($customers),
        'avg_order_value' => $counted > 0 ? round($gross / $counted, 2) : 0.0,
        'scanned'         => $counted,
        'orders_in_window' => (int) $scan['total'],
        'window_full'     => (bool) $scan['window_full'],
    ];
}

/* ---------------------------------------------------------- shaping the answer */

/** Money as a fixed-decimal string, so nothing downstream reformats 1.0e-5 as revenue. */
function nibwp_wcpro_analytics_money(float $value): string
{
    return number_format(round($value, 2), 2, '.', '');
}

/**
 * The change between two figures, in both the forms a person reads.
 *
 * A percentage against a zero baseline is not "infinite growth", it is "there was
 * nothing to compare against", and saying so is more useful than a number.
 *
 * @return array<string, mixed>
 */
function nibwp_wcpro_analytics_delta(float $now, float $before): array
{
    $change = round($now - $before, 2);
    $percent = null;
    if (abs($before) > 0.0001) {
        $percent = round(($change / abs($before)) * 100, 1);
    }

    return [
        'change'  => $change,
        'percent' => $percent,
        'direction' => abs($change) < 0.005 ? 'flat' : ($change > 0 ? 'up' : 'down'),
        'note' => $percent === null
            ? 'The previous period was zero, so there is no percentage to report — only the absolute change.'
            : '',
    ];
}

/**
 * Compare an analytics figure against a live one and say whether they agree.
 *
 * Order count is the decisive test: it is an integer, it has no sign convention and
 * no definition to argue about, so a mismatch there means the analytics tables have
 * not seen the same orders. Revenue is compared with a one percent tolerance,
 * because a partly refunded order's tax and shipping are netted slightly
 * differently by the two paths and a strict compare would cry wolf on every store
 * that has ever issued a refund.
 *
 * @return array<string, mixed>
 */
function nibwp_wcpro_analytics_cross_check(array $analytics, array $live): array
{
    $orders_agree = (int) $analytics['orders'] === (int) $live['orders'];

    $a_revenue = (float) $analytics['net_revenue'];
    $l_revenue = (float) $live['net_revenue'];
    $tolerance = max(0.01, abs(max(abs($a_revenue), abs($l_revenue))) * 0.01);
    $revenue_agree = abs($a_revenue - $l_revenue) <= $tolerance;

    // A live read that filled its window counted fewer orders than exist, so a
    // mismatch proves nothing about the analytics tables. Say that instead of
    // reporting a disagreement the data does not support.
    $inconclusive = (bool) ($live['window_full'] ?? false);

    return [
        'agree' => $inconclusive ? null : ($orders_agree && $revenue_agree),
        'inconclusive' => $inconclusive,
        'orders' => [
            'analytics_tables' => (int) $analytics['orders'],
            'live_query'       => (int) $live['orders'],
            'agree'            => $inconclusive ? null : $orders_agree,
        ],
        'net_revenue' => [
            'analytics_tables' => nibwp_wcpro_analytics_money($a_revenue),
            'live_query'       => nibwp_wcpro_analytics_money($l_revenue),
            'difference'       => nibwp_wcpro_analytics_money($a_revenue - $l_revenue),
            'tolerance'        => nibwp_wcpro_analytics_money($tolerance),
            'agree'            => $inconclusive ? null : $revenue_agree,
        ],
        'verdict' => $inconclusive
            ? 'The live read filled its window, so it counted only part of the period and cannot contradict the analytics tables. Narrow the period or raise scan_limit to make this comparison mean something.'
            : (($orders_agree && $revenue_agree)
                ? 'The analytics tables and the order book agree for this window, so the figure is safe to act on.'
                : 'The analytics tables and the order book DISAGREE for this window. Both figures are above; neither is corrected into the other. The usual cause is the analytics import running behind the order book — check that before acting on either number, and re-read afterwards.'),
    ];
}

/**
 * What each money word in this hub actually means.
 *
 * Shipped in the response rather than left to the reader, because "revenue" is
 * three different numbers depending on who is asking and the whole point of this
 * hub is that a figure travels with what it is.
 *
 * @return array<string, string>
 */
function nibwp_wcpro_analytics_definitions(): array
{
    return [
        'gross_sales' => 'The sum of order totals as charged, including tax and shipping.',
        'net_revenue' => 'Order totals less tax and less shipping, then less anything refunded. This is the definition WooCommerce analytics uses, so the two sources can be compared.',
        'refunds'     => 'The amount refunded against orders in this window, as a positive number.',
        'discounts'   => 'The coupon discount given on orders in this window.',
        'avg_order_value' => 'Gross sales divided by order count.',
        'counted_statuses' => 'Only orders in the statuses WooCommerce analytics counts are included — the store own excluded-statuses setting is honoured, so this matches the wp-admin Analytics screens rather than inventing a second rule.',
    ];
}

/* ------------------------------------------------------------ revenue + refunds */

/**
 * Read one window from whichever source can answer, and say which did.
 *
 * @return array{figures: array<string, mixed>, source: string, why: string, cross_check: ?array}|WP_Error
 */
function nibwp_wcpro_analytics_window_figures(array $window, int $scan_limit, bool $verify): array|WP_Error
{
    $analytics = nibwp_wcpro_analytics_orders_stats($window);

    if ($analytics === null) {
        $live = nibwp_wcpro_analytics_live_totals($window, $scan_limit);
        if (is_wp_error($live)) {
            return $live;
        }

        return [
            'figures' => $live,
            'source'  => 'live_query',
            'why'     => nibwp_wcpro_analytics_reports_available()
                ? 'WooCommerce analytics reporting is installed but its data store would not answer for this window — an unbuilt lookup table, or a version whose columns moved — so the order book was read directly, in a bounded window.'
                : 'WooCommerce analytics reporting is not available on this site, so the order book was read directly, in a bounded window.',
            'cross_check' => null,
        ];
    }

    $import = nibwp_wcpro_analytics_import_state();
    // Forced when the import is known to be running, because that is exactly the
    // state in which the analytics figure is confidently wrong.
    $must_verify = $verify || $import['importing'] === true;

    $cross = null;
    if ($must_verify) {
        $live = nibwp_wcpro_analytics_live_totals($window, $scan_limit);
        if (!is_wp_error($live)) {
            $cross = nibwp_wcpro_analytics_cross_check($analytics, $live);
        } else {
            $cross = [
                'agree' => null,
                'inconclusive' => true,
                'verdict' => 'The cross-check could not run: ' . $live->get_error_message(),
            ];
        }
    }

    return [
        'figures' => $analytics,
        'source'  => 'analytics_tables',
        'why'     => 'WooCommerce own analytics data store answered this window. These are the figures the wp-admin Analytics screens show.',
        'cross_check' => $cross,
    ];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_analytics_revenue(array $input): array|WP_Error
{
    $period = nibwp_wcpro_analytics_period($input);
    if (is_wp_error($period)) {
        return $period;
    }

    $scan_limit = nibwp_wcpro_analytics_bound($input, 'scan_limit');
    $verify = nibwp_wcpro_bool($input['verify'] ?? false);
    $compare = nibwp_wcpro_bool($input['compare'] ?? true, true);

    $current = nibwp_wcpro_analytics_window_figures($period, $scan_limit, $verify);
    if (is_wp_error($current)) {
        return $current;
    }

    $figures = $current['figures'];
    $result = [
        'orders'          => (int) $figures['orders'],
        'items_sold'      => (int) $figures['items_sold'],
        'gross_sales'     => nibwp_wcpro_analytics_money((float) $figures['gross_sales']),
        'net_revenue'     => nibwp_wcpro_analytics_money((float) $figures['net_revenue']),
        'refunds'         => nibwp_wcpro_analytics_money((float) $figures['refunds']),
        'taxes'           => nibwp_wcpro_analytics_money((float) $figures['taxes']),
        'shipping'        => nibwp_wcpro_analytics_money((float) $figures['shipping']),
        'discounts'       => nibwp_wcpro_analytics_money((float) $figures['discounts']),
        'avg_order_value' => nibwp_wcpro_analytics_money((float) $figures['avg_order_value']),
        'currency'        => function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : (string) get_option('woocommerce_currency', ''),
    ];

    $steps = [];
    $comparison = null;

    if ($compare) {
        $previous_window = (array) $period['previous'];
        $before = nibwp_wcpro_analytics_window_figures($previous_window, $scan_limit, false);
        if (is_wp_error($before)) {
            return $before;
        }

        $comparison = [
            'basis'  => (string) $period['comparison_basis'],
            'range'  => sprintf('%s to %s', (string) $previous_window['from'], (string) $previous_window['to']),
            'source' => (string) $before['source'],
            'orders' => (int) $before['figures']['orders'],
            'gross_sales' => nibwp_wcpro_analytics_money((float) $before['figures']['gross_sales']),
            'net_revenue' => nibwp_wcpro_analytics_money((float) $before['figures']['net_revenue']),
            'change' => [
                'net_revenue' => nibwp_wcpro_analytics_delta((float) $figures['net_revenue'], (float) $before['figures']['net_revenue']),
                'orders'      => nibwp_wcpro_analytics_delta((float) $figures['orders'], (float) $before['figures']['orders']),
                'avg_order_value' => nibwp_wcpro_analytics_delta((float) $figures['avg_order_value'], (float) $before['figures']['avg_order_value']),
            ],
        ];

        if ($before['source'] !== $current['source']) {
            $steps[] = sprintf(
                'The two periods were answered by DIFFERENT sources — this one by %s, the previous one by %s — so the change between them is not a like-for-like figure. Say so before quoting the percentage.',
                (string) $current['source'],
                (string) $before['source'],
            );
        }
    }

    if (($period['partial'] ?? false) === true) {
        $steps[] = 'This period is still running, so its figures are a partial total. The comparison window was cut to the same elapsed length, which is the only way the two are comparable.';
    }
    if (($figures['window_full'] ?? false) === true) {
        $steps[] = sprintf(
            'The live read filled its window at %d orders and the period holds %d, so every figure here is a FLOOR rather than a total. Narrow the period or raise scan_limit (max %d).',
            (int) $figures['scanned'],
            (int) ($figures['orders_in_window'] ?? $figures['scanned']),
            (int) nibwp_wcpro_analytics_bounds()['scan_limit']['max'],
        );
    }

    $disputed = is_array($current['cross_check']) && ($current['cross_check']['agree'] ?? null) === false;
    if ($disputed) {
        $steps[] = 'The analytics tables and the order book disagree for this window. Both numbers are in cross_check; do not reconcile them for the user, and do not act on either until the analytics import has caught up.';
    } elseif ($current['source'] === 'analytics_tables' && !is_array($current['cross_check'])) {
        $steps[] = 'These are analytics-table figures and were not cross-checked. Pass verify:true to read the same window from the order book as well — worth doing before anyone acts on the number.';
    }

    return nibwp_wcpro_ok(
        'revenue_summary',
        sprintf(
            '%s net revenue from %d order(s), %s — figures from %s.',
            $result['net_revenue'],
            $result['orders'],
            (string) $period['resolved'],
            (string) $current['source'],
        ),
        nibwp_wcpro_analytics_provenance((string) $current['source'], (string) $current['why']) + [
            'period'      => $period,
            'result'      => $result,
            'comparison'  => $comparison,
            'cross_check' => $current['cross_check'],
            'disputed'    => $disputed,
            'definitions' => nibwp_wcpro_analytics_definitions(),
            'next_steps'  => $steps,
        ],
    );
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_analytics_refunds(array $input): array|WP_Error
{
    $period = nibwp_wcpro_analytics_period($input);
    if (is_wp_error($period)) {
        return $period;
    }

    $scan_limit = nibwp_wcpro_analytics_bound($input, 'scan_limit');
    $compare = nibwp_wcpro_bool($input['compare'] ?? true, true);

    $current = nibwp_wcpro_analytics_window_figures($period, $scan_limit, nibwp_wcpro_bool($input['verify'] ?? false));
    if (is_wp_error($current)) {
        return $current;
    }

    $rate = nibwp_wcpro_analytics_refund_rate_block($current['figures']);

    $comparison = null;
    $steps = [];

    if ($compare) {
        $previous_window = (array) $period['previous'];
        $before = nibwp_wcpro_analytics_window_figures($previous_window, $scan_limit, false);
        if (is_wp_error($before)) {
            return $before;
        }
        $before_rate = nibwp_wcpro_analytics_refund_rate_block($before['figures']);
        $comparison = [
            'basis'  => (string) $period['comparison_basis'],
            'range'  => sprintf('%s to %s', (string) $previous_window['from'], (string) $previous_window['to']),
            'source' => (string) $before['source'],
            'result' => $before_rate,
            'change' => [
                'value_rate_percent' => nibwp_wcpro_analytics_delta((float) $rate['value_rate_percent'], (float) $before_rate['value_rate_percent']),
                'order_rate_percent' => nibwp_wcpro_analytics_delta((float) $rate['order_rate_percent'], (float) $before_rate['order_rate_percent']),
            ],
        ];
        if (((float) $rate['value_rate_percent']) > ((float) $before_rate['value_rate_percent']) + 1.0) {
            $steps[] = 'The refund rate rose by more than a point against the previous period. Run top_sellers over the same window: a rise concentrated in one product is a product problem, a rise spread across the catalogue is a fulfilment or delivery problem.';
        }
    }

    if (($current['figures']['window_full'] ?? false) === true) {
        $steps[] = 'The live read filled its window, so this rate is computed over part of the period only. Narrow the period rather than quoting it as the store rate.';
    }
    if ($current['source'] === 'analytics_tables') {
        $steps[] = 'A refund recorded today may not be in the analytics tables yet, which makes a very recent refund rate read low. For this week, period:"today" with verify:true is the honest read.';
    }

    return nibwp_wcpro_ok(
        'refund_rate',
        sprintf(
            '%s%% of takings and %s%% of orders refunded, %s — figures from %s.',
            (string) $rate['value_rate_percent'],
            (string) $rate['order_rate_percent'],
            (string) $period['resolved'],
            (string) $current['source'],
        ),
        nibwp_wcpro_analytics_provenance((string) $current['source'], (string) $current['why']) + [
            'period'      => $period,
            'result'      => $rate,
            'comparison'  => $comparison,
            'cross_check' => $current['cross_check'],
            'definitions' => nibwp_wcpro_analytics_definitions(),
            'next_steps'  => $steps,
        ],
    );
}

/**
 * The refund rate, both ways round.
 *
 * Two rates rather than one, because they answer different questions: the share of
 * money handed back is what the accounts care about, and the share of ORDERS
 * refunded is what tells you whether it was one expensive mistake or a pattern.
 *
 * @return array<string, mixed>
 */
function nibwp_wcpro_analytics_refund_rate_block(array $figures): array
{
    $gross = (float) $figures['gross_sales'];
    $refunds = (float) $figures['refunds'];
    $orders = (int) $figures['orders'];
    $refunded_orders = array_key_exists('refunded_orders', $figures) ? (int) $figures['refunded_orders'] : null;

    return [
        'gross_sales'        => nibwp_wcpro_analytics_money($gross),
        'refunds'            => nibwp_wcpro_analytics_money($refunds),
        'orders'             => $orders,
        'refunded_orders'    => $refunded_orders,
        'value_rate_percent' => $gross > 0 ? (string) round(($refunds / $gross) * 100, 2) : '0',
        'order_rate_percent' => ($refunded_orders !== null && $orders > 0) ? (string) round(($refunded_orders / $orders) * 100, 2) : '0',
        'order_rate_known'   => $refunded_orders !== null,
        'note' => $refunded_orders === null
            ? 'The analytics tables report refunded AMOUNTS for a window but not how many orders they came from, so the order-level rate is not available from that source. Pass verify:true, or read a shorter period, to get it from the order book.'
            : '',
    ];
}

/* ---------------------------------------------------------------- top sellers */

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_analytics_top_sellers(array $input): array|WP_Error
{
    $period = nibwp_wcpro_analytics_period($input);
    if (is_wp_error($period)) {
        return $period;
    }

    $orderby = nibwp_wcpro_enum($input['orderby'] ?? 'items_sold', ['items_sold', 'net_revenue'], 'orderby', 'items_sold');
    if (is_wp_error($orderby)) {
        return $orderby;
    }

    $limit = nibwp_wcpro_analytics_bound($input, 'limit');
    $scan_limit = nibwp_wcpro_analytics_bound($input, 'scan_limit');

    $rows = nibwp_wcpro_analytics_products_report($period, $orderby, $limit);
    $source = 'analytics_tables';
    $why = 'WooCommerce own product lookup table answered, which is what the Analytics → Products screen shows.';
    $window_full = false;
    $scanned = null;

    if ($rows === null) {
        $live = nibwp_wcpro_analytics_live_products($period, $orderby, $limit, $scan_limit);
        if (is_wp_error($live)) {
            return $live;
        }
        $rows = $live['rows'];
        $window_full = (bool) $live['window_full'];
        $scanned = (int) $live['scanned'];
        $source = 'live_query';
        $why = nibwp_wcpro_analytics_reports_available()
            ? 'The product lookup table would not answer for this window, so the ranking was built by walking the orders in a bounded window.'
            : 'WooCommerce analytics reporting is not available on this site, so the ranking was built by walking the orders in a bounded window.';
    }

    // Per product, because a row names an object. A product the account may not
    // open in wp-admin is counted rather than quietly folded into the ranking.
    $items = [];
    $skipped = 0;
    foreach ($rows as $row) {
        $product_id = (int) ($row['product_id'] ?? 0);
        if ($product_id > 0 && !current_user_can('edit_post', $product_id)) {
            $skipped++;
            continue;
        }
        $row['net_revenue'] = nibwp_wcpro_analytics_money((float) ($row['net_revenue'] ?? 0));
        $items[] = $row;
    }

    $steps = [];
    if ($skipped > 0) {
        $steps[] = sprintf('%d product(s) in this ranking are not readable by the connected account and were left out. The ranking below is therefore partial; say so.', $skipped);
    }
    if ($window_full) {
        $steps[] = sprintf('The live walk filled its window at %d orders, so this is the ranking within the most recent orders of the period rather than across all of it. Narrow the period or raise scan_limit.', (int) $scanned);
    }
    if ($items !== [] && $orderby === 'items_sold') {
        $steps[] = 'This is ranked by units. orderby:"net_revenue" usually gives a different list, and the difference between the two lists is where the margin conversation starts.';
    }

    return nibwp_wcpro_ok(
        'top_sellers',
        sprintf(
            '%d product(s) ranked by %s, %s — figures from %s.',
            count($items),
            $orderby,
            (string) $period['resolved'],
            $source,
        ),
        nibwp_wcpro_analytics_provenance($source, $why) + [
            'period' => $period,
            'result' => ['orderby' => $orderby, 'limit' => $limit, 'skipped' => $skipped, 'scanned_orders' => $scanned, 'window_full' => $window_full],
            'items'  => $items,
            'count'  => count($items),
            'next_steps' => $steps,
        ],
    );
}

/**
 * A product ranking built from the orders themselves.
 *
 * @return array{rows: array<int, array<string, mixed>>, scanned: int, window_full: bool}|WP_Error
 */
function nibwp_wcpro_analytics_live_products(array $window, string $orderby, int $limit, int $scan_limit): array|WP_Error
{
    $scan = nibwp_wcpro_analytics_scan_orders($window, $scan_limit);
    if (is_wp_error($scan)) {
        return $scan;
    }

    $totals = [];
    foreach ($scan['orders'] as $order) {
        if (!method_exists($order, 'get_items')) {
            continue;
        }
        foreach ((array) $order->get_items() as $item) {
            if (!is_object($item) || !method_exists($item, 'get_product_id')) {
                continue;
            }
            $product_id = (int) $item->get_product_id();
            if ($product_id <= 0) {
                continue;
            }
            if (!isset($totals[$product_id])) {
                $totals[$product_id] = [
                    'product_id'  => $product_id,
                    'name'        => method_exists($item, 'get_name') ? (string) $item->get_name() : '',
                    'sku'         => '',
                    'items_sold'  => 0,
                    'net_revenue' => 0.0,
                    'orders'      => 0,
                ];
            }
            $totals[$product_id]['items_sold'] += method_exists($item, 'get_quantity') ? (int) $item->get_quantity() : 0;
            $totals[$product_id]['net_revenue'] += method_exists($item, 'get_total') ? (float) $item->get_total() : 0.0;
            $totals[$product_id]['orders']++;
        }
    }

    $rows = array_values($totals);
    usort($rows, static fn (array $a, array $b): int => $b[$orderby] <=> $a[$orderby]);

    return [
        'rows'        => array_slice($rows, 0, $limit),
        'scanned'     => count($scan['orders']),
        'window_full' => (bool) $scan['window_full'],
    ];
}

/* ------------------------------------------------------------------- the stock */

/**
 * Low stock and out of stock.
 *
 * ALWAYS live_query, and that is not a limitation. Stock is not a statistic — it is
 * the product record, the same row the shop reads to decide whether the add-to-cart
 * button works. There is no analytics table to lag behind it, so this is the one
 * answer in the hub that cannot be stale, and the response says so rather than
 * leaving the reader to wonder why the source differs from the rest.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_analytics_low_stock(array $input): array|WP_Error
{
    $denied = nibwp_wcpro_require_function('wc_get_product', 'Reading product stock');
    if ($denied !== null) {
        return $denied;
    }
    if (!function_exists('get_posts')) {
        return nibwp_wcpro_err('wp_function_missing', 'get_posts() is not available, so the catalogue cannot be queried for stock.', 500);
    }

    $limit = nibwp_wcpro_analytics_bound($input, 'stock_limit');
    $store_threshold = (int) get_option('woocommerce_notify_low_stock_amount', 2);
    $threshold = array_key_exists('threshold', $input)
        ? nibwp_wcpro_analytics_bound($input, 'threshold')
        : max(0, $store_threshold);

    $low_query = nibwp_wcpro_analytics_low_stock_meta($threshold);
    $out_query = nibwp_wcpro_analytics_out_of_stock_meta();

    $low = nibwp_wcpro_analytics_stock_rows($low_query, $limit, '_stock');
    $out = nibwp_wcpro_analytics_stock_rows($out_query, $limit, '');

    // The counts are their own query, capped at the ceiling rather than at the
    // caller's stock_limit. Counting the rows of a 10-row list and calling the
    // answer "10 products are out of stock" is the failure this separates out: the
    // lists are a sample the caller sized, the counts are the figure an owner acts
    // on, and conflating them understates an emptying shop.
    $counts = nibwp_wcpro_analytics_stock_counts($low_query, $out_query);

    $steps = [];
    if ($counts['out'] > 0) {
        $steps[] = sprintf('%s product(s) are out of stock and cannot be bought right now. That is the list to act on first.', nibwp_wcpro_analytics_count_label($counts['out'], $counts['out_capped']));
    }
    if ($counts['low'] > 0) {
        $steps[] = sprintf('%s product(s) are at or below the threshold of %d. Reorder, or raise the threshold if it is set too keenly for this catalogue.', nibwp_wcpro_analytics_count_label($counts['low'], $counts['low_capped']), $threshold);
    }
    if ($low['window_full'] || $out['window_full']) {
        $steps[] = sprintf('A list filled its %d-row window, so it shows a sample rather than everything the counts describe. Raise stock_limit (max %d) or work through these first.', $limit, (int) nibwp_wcpro_analytics_bounds()['stock_limit']['max']);
    }
    if ($counts['out_capped'] || $counts['low_capped']) {
        $steps[] = sprintf('A count hit its ceiling of %d, so it is a FLOOR rather than a total. Report it as "at least".', (int) $counts['ceiling']);
    }
    $skipped = (int) $low['skipped'] + (int) $out['skipped'];
    if ($skipped > 0) {
        $steps[] = sprintf('%d product(s) were left out of the lists because the connected account may not read them. Report that rather than presenting the lists as complete.', $skipped);
    }

    return nibwp_wcpro_ok(
        'low_stock',
        sprintf(
            '%s product(s) out of stock, %s at or below %d in stock.',
            nibwp_wcpro_analytics_count_label($counts['out'], $counts['out_capped']),
            nibwp_wcpro_analytics_count_label($counts['low'], $counts['low_capped']),
            $threshold,
        ),
        nibwp_wcpro_analytics_provenance(
            'live_query',
            'Stock has no analytics table — it is the product record itself — so this is always a live read and is never behind the shop.',
        ) + [
            'result' => [
                'threshold'          => $threshold,
                'store_threshold'    => $store_threshold,
                'threshold_from_input' => array_key_exists('threshold', $input),
                'low_stock_count'    => (int) $counts['low'],
                'out_of_stock_count' => (int) $counts['out'],
                'counts_are_floors'  => (bool) ($counts['low_capped'] || $counts['out_capped']),
                'count_ceiling'      => (int) $counts['ceiling'],
                'listed_low'         => count($low['items']),
                'listed_out'         => count($out['items']),
                'skipped'            => $skipped,
                'window_full'        => $low['window_full'] || $out['window_full'],
                'variations_scanned' => false,
            ],
            'low_stock'    => $low['items'],
            'out_of_stock' => $out['items'],
            'count' => count($low['items']) + count($out['items']),
            'next_steps' => $steps,
        ],
    );
}

/**
 * The meta conditions for "low but not gone", kept in one place so the list query
 * and the count query cannot drift apart and report two different shops.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_wcpro_analytics_low_stock_meta(int $threshold): array
{
    return [
        ['key' => '_manage_stock', 'value' => 'yes'],
        ['key' => '_stock', 'value' => $threshold, 'compare' => '<=', 'type' => 'NUMERIC'],
    ];
}

/** @return array<int, array<string, mixed>> */
function nibwp_wcpro_analytics_out_of_stock_meta(): array
{
    return [
        ['key' => '_stock_status', 'value' => 'outofstock'],
    ];
}

/**
 * How many products are low and how many are gone, counted without hydrating any.
 *
 * Capped at the stock_limit ceiling from the bounds table rather than at anything a
 * caller can pass, and the cap is reported, because a count that silently stopped
 * at 200 reads as "only 200" to whoever quotes it.
 *
 * @param array<int, array<string, mixed>> $low_query
 * @param array<int, array<string, mixed>> $out_query
 * @return array{low: int, out: int, ceiling: int, low_capped: bool, out_capped: bool}
 */
function nibwp_wcpro_analytics_stock_counts(array $low_query, array $out_query): array
{
    $ceiling = (int) nibwp_wcpro_analytics_bounds()['stock_limit']['max'];

    $low = nibwp_wcpro_analytics_count_products($low_query, $ceiling);
    $out = nibwp_wcpro_analytics_count_products($out_query, $ceiling);

    return [
        'low'        => $low,
        'out'        => $out,
        'ceiling'    => $ceiling,
        'low_capped' => $low >= $ceiling,
        'out_capped' => $out >= $ceiling,
    ];
}

/**
 * Ids only, up to the ceiling. No product objects, no capability checks — nothing
 * here names a product, so there is no object to ask about, and a count of rows the
 * account could not open would still be the right count.
 *
 * @param array<int, array<string, mixed>> $meta_query
 */
function nibwp_wcpro_analytics_count_products(array $meta_query, int $ceiling): int
{
    return count((array) get_posts([
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => $ceiling,
        'fields'         => 'ids',
        'meta_query'     => $meta_query,
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'no_found_rows'  => true,
    ]));
}

/** "12", or "at least 200" when the count stopped at its ceiling. */
function nibwp_wcpro_analytics_count_label(int $count, bool $capped): string
{
    return $capped ? sprintf('at least %d', $count) : (string) $count;
}

/**
 * One bounded stock query, with the per-product capability check inside it.
 *
 * Variations are not walked. A variable product keeps its stock per variation in a
 * separate post type, so walking them would multiply the query count by the size of
 * every grid in the shop; the parent row is reported and the response says the
 * variations were not looked at, which is a smaller lie than a number that quietly
 * excludes them.
 *
 * @param array<int, array<string, mixed>> $meta_query
 * @return array{items: array<int, array<string, mixed>>, skipped: int, window_full: bool}
 */
function nibwp_wcpro_analytics_stock_rows(array $meta_query, int $limit, string $order_by_meta): array
{
    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'fields'         => 'ids',
        'meta_query'     => $meta_query,
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'no_found_rows'  => true,
    ];
    if ($order_by_meta !== '') {
        $args['meta_key'] = $order_by_meta;
        $args['orderby'] = 'meta_value_num';
    }

    $ids = array_map('intval', (array) get_posts($args));

    $items = [];
    $skipped = 0;
    foreach ($ids as $id) {
        if (!current_user_can('edit_post', $id)) {
            $skipped++;
            continue;
        }
        $product = wc_get_product($id);
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            $skipped++;
            continue;
        }
        $items[] = [
            'product_id'   => (int) $product->get_id(),
            'name'         => method_exists($product, 'get_name') ? (string) $product->get_name() : '',
            'sku'          => method_exists($product, 'get_sku') ? (string) $product->get_sku() : '',
            'type'         => method_exists($product, 'get_type') ? (string) $product->get_type() : '',
            'stock'        => method_exists($product, 'get_stock_quantity') ? $product->get_stock_quantity() : null,
            'stock_status' => method_exists($product, 'get_stock_status') ? (string) $product->get_stock_status() : '',
            'manage_stock' => method_exists($product, 'get_manage_stock') ? (bool) $product->get_manage_stock() : false,
            'backorders'   => method_exists($product, 'get_backorders') ? (string) $product->get_backorders() : '',
            'own_low_stock_amount' => method_exists($product, 'get_low_stock_amount') ? $product->get_low_stock_amount() : null,
        ];
    }

    return ['items' => $items, 'skipped' => $skipped, 'window_full' => count($ids) >= $limit];
}

/* ----------------------------------------------------------------- the coupons */

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_analytics_coupons(array $input): array|WP_Error
{
    $period = nibwp_wcpro_analytics_period($input);
    if (is_wp_error($period)) {
        return $period;
    }

    $limit = nibwp_wcpro_analytics_bound($input, 'coupon_limit');
    $scan_limit = nibwp_wcpro_analytics_bound($input, 'scan_limit');
    $include_unused = nibwp_wcpro_bool($input['include_unused'] ?? true, true);

    $rows = nibwp_wcpro_analytics_coupons_report($period, $limit);
    $source = 'analytics_tables';
    $why = 'WooCommerce own coupon lookup table answered, which is what the Analytics → Coupons screen shows.';
    $window_full = false;

    if ($rows === null) {
        $live = nibwp_wcpro_analytics_live_coupons($period, $limit, $scan_limit);
        if (is_wp_error($live)) {
            return $live;
        }
        $rows = $live['rows'];
        $window_full = (bool) $live['window_full'];
        $source = 'live_query';
        $why = nibwp_wcpro_analytics_reports_available()
            ? 'The coupon lookup table would not answer for this window, so coupon use was read off the orders in a bounded window.'
            : 'WooCommerce analytics reporting is not available on this site, so coupon use was read off the orders in a bounded window.';
    }

    $inventory = nibwp_wcpro_analytics_coupon_inventory($limit);

    $used = [];
    $deleted = [];
    $skipped = 0;
    foreach ($rows as $row) {
        $code = (string) $row['code'];
        $coupon_id = (int) ($row['coupon_id'] ?? 0);
        if ($coupon_id <= 0) {
            $coupon_id = (int) ($inventory['by_code'][$code]['coupon_id'] ?? 0);
        }

        if ($coupon_id <= 0) {
            // A code on an order whose coupon no longer exists. Worth its own list:
            // it is either a deleted campaign still being honoured by old orders, or
            // a code somebody removed while it was live.
            $deleted[] = ['code' => $code, 'orders' => (int) $row['orders'], 'discount' => nibwp_wcpro_analytics_money((float) $row['discount'])];
            continue;
        }
        if (!current_user_can('edit_post', $coupon_id)) {
            $skipped++;
            continue;
        }

        $known = $inventory['by_code'][$code] ?? [];
        $used[] = [
            'coupon_id'     => $coupon_id,
            'code'          => $code,
            'orders'        => (int) $row['orders'],
            'discount'      => nibwp_wcpro_analytics_money((float) $row['discount']),
            'discount_type' => (string) ($known['discount_type'] ?? ''),
            'amount'        => (string) ($known['amount'] ?? ''),
            'usage_count_lifetime' => $known['usage_count'] ?? null,
            'usage_limit'   => $known['usage_limit'] ?? null,
            'expires'       => (string) ($known['expires'] ?? ''),
        ];
    }

    $unused = [];
    if ($include_unused) {
        $seen = [];
        foreach ($used as $row) {
            $seen[(string) $row['code']] = true;
        }
        foreach ($inventory['by_code'] as $code => $coupon) {
            if (!isset($seen[$code])) {
                $unused[] = $coupon;
            }
        }
    }

    $steps = [];
    if ($unused !== []) {
        $steps[] = sprintf('%d published coupon(s) were used zero times in this period. A live code that nobody uses is either undiscoverable or unattractive, and both are fixable.', count($unused));
    }
    if ($deleted !== []) {
        $steps[] = sprintf('%d coupon code(s) appear on orders in this period but no longer exist as coupons. Those discounts were given and cannot be audited from the coupon screen.', count($deleted));
    }
    if ($skipped > 0) {
        $steps[] = sprintf('%d coupon(s) were left out because the connected account may not read them.', $skipped);
    }
    if ($window_full) {
        $steps[] = 'The live walk filled its order window, so these coupon totals cover only the most recent orders of the period. Narrow the period before quoting them.';
    }
    if ($inventory['window_full']) {
        $steps[] = sprintf('The coupon inventory filled its %d-row window, so the unused list is not exhaustive. Raise coupon_limit (max %d).', $limit, (int) nibwp_wcpro_analytics_bounds()['coupon_limit']['max']);
    }

    return nibwp_wcpro_ok(
        'coupon_performance',
        sprintf(
            '%d coupon(s) used, %d unused, %s — figures from %s.',
            count($used),
            count($unused),
            (string) $period['resolved'],
            $source,
        ),
        nibwp_wcpro_analytics_provenance($source, $why) + [
            'period' => $period,
            'result' => [
                'used_count'   => count($used),
                'unused_count' => count($unused),
                'deleted_code_count' => count($deleted),
                'skipped'      => $skipped,
                'window_full'  => $window_full || $inventory['window_full'],
            ],
            'items'   => $used,
            'unused'  => $unused,
            'deleted_codes' => $deleted,
            'count'   => count($used),
            'next_steps' => $steps,
        ],
    );
}

/**
 * The published coupons this account may read, keyed by lower-cased code.
 *
 * Needed even when the analytics tables answered: the lookup table knows what was
 * used and cannot know what was not, and "which of my coupons nobody used" is the
 * question a coupon report is usually asked.
 *
 * @return array{by_code: array<string, array<string, mixed>>, window_full: bool}
 */
function nibwp_wcpro_analytics_coupon_inventory(int $limit): array
{
    if (!function_exists('get_posts') || !class_exists('WC_Coupon')) {
        return ['by_code' => [], 'window_full' => false];
    }

    $ids = array_map('intval', (array) get_posts([
        'post_type'      => 'shop_coupon',
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'fields'         => 'ids',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'no_found_rows'  => true,
    ]));

    $by_code = [];
    foreach ($ids as $id) {
        if (!current_user_can('edit_post', $id)) {
            continue;
        }
        try {
            $coupon = new WC_Coupon($id);
        } catch (Throwable $e) {
            continue;
        }
        $code = strtolower(trim((string) $coupon->get_code()));
        if ($code === '') {
            continue;
        }
        $expires = $coupon->get_date_expires();
        $by_code[$code] = [
            'coupon_id'     => $id,
            'code'          => $code,
            'orders'        => 0,
            'discount'      => '0.00',
            'discount_type' => (string) $coupon->get_discount_type(),
            'amount'        => (string) $coupon->get_amount(),
            'usage_count'   => (int) $coupon->get_usage_count(),
            'usage_limit'   => $coupon->get_usage_limit(),
            'expires'       => is_object($expires) && method_exists($expires, 'date') ? (string) $expires->date('Y-m-d') : '',
        ];
    }

    return ['by_code' => $by_code, 'window_full' => count($ids) >= $limit];
}

/**
 * Coupon use read off the orders themselves.
 *
 * @return array{rows: array<int, array<string, mixed>>, window_full: bool}|WP_Error
 */
function nibwp_wcpro_analytics_live_coupons(array $window, int $limit, int $scan_limit): array|WP_Error
{
    $scan = nibwp_wcpro_analytics_scan_orders($window, $scan_limit);
    if (is_wp_error($scan)) {
        return $scan;
    }

    $totals = [];
    foreach ($scan['orders'] as $order) {
        $codes = [];
        if (method_exists($order, 'get_coupon_codes')) {
            $codes = (array) $order->get_coupon_codes();
        } elseif (method_exists($order, 'get_used_coupons')) {
            $codes = (array) $order->get_used_coupons();
        }
        if ($codes === []) {
            continue;
        }

        // The order's discount is not attributed per coupon by WooCommerce when an
        // order used several, so it is divided evenly and the response says the
        // number is a share rather than an audited figure.
        $discount = method_exists($order, 'get_discount_total') ? (float) $order->get_discount_total() : 0.0;
        $share = count($codes) > 0 ? $discount / count($codes) : 0.0;

        foreach ($codes as $code) {
            $code = strtolower(trim((string) $code));
            if ($code === '') {
                continue;
            }
            if (!isset($totals[$code])) {
                $totals[$code] = ['coupon_id' => 0, 'code' => $code, 'orders' => 0, 'discount' => 0.0];
            }
            $totals[$code]['orders']++;
            $totals[$code]['discount'] += $share;
        }
    }

    foreach ($totals as $code => $row) {
        if (function_exists('wc_get_coupon_id_by_code')) {
            $totals[$code]['coupon_id'] = (int) wc_get_coupon_id_by_code($code);
        }
    }

    $rows = array_values($totals);
    usort($rows, static fn (array $a, array $b): int => $b['orders'] <=> $a['orders']);

    return ['rows' => array_slice($rows, 0, $limit), 'window_full' => (bool) $scan['window_full']];
}

/* -------------------------------------------------------------- acquisition */

/**
 * New customers over the period, in buckets.
 *
 * Counts REGISTRATIONS, and says so in the response. A store with guest checkout
 * acquires customers it never registers, so this series is the shape of ACCOUNT
 * signups rather than of the customer base; presenting it as the latter would
 * understate a store whose customers mostly never make an account.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_analytics_acquisition(array $input): array|WP_Error
{
    $period = nibwp_wcpro_analytics_period($input);
    if (is_wp_error($period)) {
        return $period;
    }

    $zone = nibwp_wcpro_analytics_timezone();
    $buckets = nibwp_wcpro_analytics_buckets($period, nibwp_wcpro_analytics_bound($input, 'buckets'), $zone);

    $total = nibwp_wcpro_analytics_customers_registered((string) $period['from'], (string) $period['to']);
    $source = 'analytics_tables';
    $why = 'WooCommerce own customer lookup table answered, which is what the Analytics → Customers screen counts.';
    $series = [];
    $window_full = false;
    $scanned = null;

    if ($total !== null) {
        foreach ($buckets as $bucket) {
            $count = nibwp_wcpro_analytics_customers_registered(
                nibwp_wcpro_analytics_stamp((int) $bucket['from'], $zone, 'Y-m-d H:i:s'),
                nibwp_wcpro_analytics_stamp((int) $bucket['to'], $zone, 'Y-m-d H:i:s'),
            );
            if ($count === null) {
                // Half a series is worse than none: it looks like a fall. Drop the
                // analytics attempt entirely and let the live path answer.
                $series = [];
                $total = null;
                break;
            }
            $series[] = ['from' => (string) $bucket['from_date'], 'to' => (string) $bucket['to_date'], 'label' => (string) $bucket['label'], 'customers' => $count];
        }
    }

    if ($total === null) {
        $live = nibwp_wcpro_analytics_live_acquisition($period, $buckets, $input);
        if (is_wp_error($live)) {
            return $live;
        }
        $series = $live['series'];
        $total = (int) $live['total'];
        $window_full = (bool) $live['window_full'];
        $scanned = (int) $live['scanned'];
        $source = 'live_query';
        $why = nibwp_wcpro_analytics_reports_available()
            ? 'The customer lookup table would not answer for this window, so registrations were counted from the user table in a bounded window.'
            : 'WooCommerce analytics reporting is not available on this site, so registrations were counted from the user table in a bounded window.';
    }

    $steps = [
        'These are ACCOUNT registrations, not first orders. A store with guest checkout acquires customers who never appear here — pair this with revenue_summary before calling it acquisition.',
    ];
    if ($window_full) {
        $steps[] = sprintf('The live scan read %d of %d registrations in the window, so the per-bucket shape is partial although the total is exact. Narrow the period for an accurate series.', (int) $scanned, (int) $total);
    }

    return nibwp_wcpro_ok(
        'customer_acquisition',
        sprintf('%d new customer account(s), %s — figures from %s.', (int) $total, (string) $period['resolved'], $source),
        nibwp_wcpro_analytics_provenance($source, $why) + [
            'period' => $period,
            'result' => [
                'new_customers' => (int) $total,
                'buckets'       => count($series),
                'counts'        => 'registrations',
                'scanned'       => $scanned,
                'window_full'   => $window_full,
            ],
            'items' => $series,
            'count' => count($series),
            'next_steps' => $steps,
        ],
    );
}

/**
 * Registrations counted off the user table.
 *
 * One query, not one per bucket: the exact total comes from count_total, and the
 * per-bucket shape is built in PHP from the rows that fit inside the bound. That
 * way a store whose window overflows still gets a true total and is told the shape
 * is partial, rather than getting a plausible chart built out of a third of the data.
 *
 * @param array<int, array<string, mixed>> $buckets
 * @return array{series: array<int, array<string, mixed>>, total: int, scanned: int, window_full: bool}|WP_Error
 */
function nibwp_wcpro_analytics_live_acquisition(array $window, array $buckets, array $input): array|WP_Error
{
    if (!class_exists('WP_User_Query')) {
        return nibwp_wcpro_err('wp_class_missing', 'WP_User_Query is not available, so registrations cannot be counted.', 500);
    }

    $scan_limit = nibwp_wcpro_analytics_bound($input, 'scan_limit');

    $query = new WP_User_Query([
        'number'      => $scan_limit,
        'orderby'     => 'registered',
        'order'       => 'ASC',
        'fields'      => ['ID', 'user_registered'],
        'count_total' => true,
        'date_query'  => [
            [
                // user_registered is stored in UTC, so the window is handed over in
                // UTC. Handing it local time is the bug this whole file is about.
                'after'     => (string) $window['from_utc'],
                'before'    => (string) $window['to_utc'],
                'inclusive' => true,
                'column'    => 'user_registered',
            ],
        ],
    ]);

    $rows = (array) $query->get_results();
    $total = (int) $query->get_total();

    $counts = array_fill(0, max(1, count($buckets)), 0);
    foreach ($rows as $row) {
        $registered = is_object($row) ? (string) ($row->user_registered ?? '') : '';
        if ($registered === '') {
            continue;
        }
        $stamp = strtotime($registered . ' UTC');
        if ($stamp === false) {
            continue;
        }
        foreach ($buckets as $index => $bucket) {
            if ($stamp >= (int) $bucket['from'] && $stamp <= (int) $bucket['to']) {
                $counts[$index]++;
                break;
            }
        }
    }

    $series = [];
    foreach ($buckets as $index => $bucket) {
        $series[] = [
            'from'      => (string) $bucket['from_date'],
            'to'        => (string) $bucket['to_date'],
            'label'     => (string) $bucket['label'],
            'customers' => (int) ($counts[$index] ?? 0),
        ];
    }

    return [
        'series'      => $series,
        'total'       => $total,
        'scanned'     => count($rows),
        'window_full' => count($rows) >= $scan_limit && $total > count($rows),
    ];
}

/* ------------------------------------------------------------- store health */

/**
 * The handful of numbers an owner acts on, in one response.
 *
 * Six figures, each with its own source, because this is the one action that
 * legitimately mixes them: revenue can come from either place, stock never comes
 * from the analytics tables, and a response that flattened that to a single claim
 * would be wrong about at least one of its own numbers. The top-level `source`
 * names where the HEADLINE — revenue — came from, and mixed_sources says not to
 * assume the rest agree.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_analytics_store_health(array $input): array|WP_Error
{
    $period = nibwp_wcpro_analytics_period($input);
    if (is_wp_error($period)) {
        return $period;
    }

    $scan_limit = nibwp_wcpro_analytics_bound($input, 'scan_limit');
    $verify = nibwp_wcpro_bool($input['verify'] ?? false);

    $current = nibwp_wcpro_analytics_window_figures($period, $scan_limit, $verify);
    if (is_wp_error($current)) {
        return $current;
    }
    $before = nibwp_wcpro_analytics_window_figures((array) $period['previous'], $scan_limit, false);
    if (is_wp_error($before)) {
        return $before;
    }

    $figures = $current['figures'];
    $headline = (string) $current['source'];
    $sources = [$headline, (string) $before['source']];
    $steps = [];

    $revenue = [
        'source'      => $headline,
        'net_revenue' => nibwp_wcpro_analytics_money((float) $figures['net_revenue']),
        'gross_sales' => nibwp_wcpro_analytics_money((float) $figures['gross_sales']),
        'orders'      => (int) $figures['orders'],
        'avg_order_value' => nibwp_wcpro_analytics_money((float) $figures['avg_order_value']),
        'previous_net_revenue' => nibwp_wcpro_analytics_money((float) $before['figures']['net_revenue']),
        'change'      => nibwp_wcpro_analytics_delta((float) $figures['net_revenue'], (float) $before['figures']['net_revenue']),
    ];

    $refunds = nibwp_wcpro_analytics_refund_rate_block($figures);
    $refunds['source'] = $headline;

    // Stock is its own read and its own source, always live. stock_limit 1 because
    // a summary wants the counts, and the counts come from their own query rather
    // than from the length of the sample list.
    $stock = nibwp_wcpro_analytics_low_stock(['stock_limit' => 1]);
    $stock_block = ['source' => 'live_query', 'out_of_stock_count' => null, 'low_stock_count' => null];
    if (!is_wp_error($stock)) {
        $stock_block = [
            'source'             => (string) $stock['source'],
            'out_of_stock_count' => (int) $stock['result']['out_of_stock_count'],
            'low_stock_count'    => (int) $stock['result']['low_stock_count'],
            'threshold'          => (int) $stock['result']['threshold'],
            'counts_are_floors'  => (bool) $stock['result']['counts_are_floors'],
        ];
        $sources[] = (string) $stock['source'];
        if ((int) $stock['result']['out_of_stock_count'] > 0) {
            $steps[] = 'There is at least one product out of stock. Run low_stock for the list — a shop cannot sell what it does not have and nothing on the storefront says so loudly.';
        }
    } else {
        $steps[] = 'Stock could not be read for this summary: ' . $stock->get_error_message();
    }

    $sellers = nibwp_wcpro_analytics_top_sellers(['period' => (string) $period['period'], 'date_from' => (string) ($input['date_from'] ?? ''), 'date_to' => (string) ($input['date_to'] ?? ''), 'limit' => 1]);
    $top_block = ['source' => null, 'product' => null];
    if (!is_wp_error($sellers)) {
        $top_block = [
            'source'  => (string) $sellers['source'],
            'product' => $sellers['items'][0] ?? null,
        ];
        $sources[] = (string) $sellers['source'];
    }

    $acquisition = nibwp_wcpro_analytics_acquisition(['period' => (string) $period['period'], 'date_from' => (string) ($input['date_from'] ?? ''), 'date_to' => (string) ($input['date_to'] ?? ''), 'buckets' => 1]);
    $customers_block = ['source' => null, 'new_customers' => null];
    if (!is_wp_error($acquisition)) {
        $customers_block = [
            'source'        => (string) $acquisition['source'],
            'new_customers' => (int) $acquisition['result']['new_customers'],
            'counts'        => 'registrations',
        ];
        $sources[] = (string) $acquisition['source'];
    }

    $unique_sources = array_values(array_unique(array_filter($sources, static fn ($source): bool => is_string($source) && $source !== '')));
    $mixed = count($unique_sources) > 1;

    $disputed = is_array($current['cross_check']) && ($current['cross_check']['agree'] ?? null) === false;
    if ($disputed) {
        $steps[] = 'The analytics tables and the order book disagree about this period. Both figures are in cross_check. Do not present a single revenue number until the import has caught up.';
    }
    if ($mixed) {
        $steps[] = sprintf('The figures in this summary came from more than one source (%s). Each one carries its own; do not present them as a single reading of the store.', implode(' and ', $unique_sources));
    }
    if (((float) $refunds['value_rate_percent']) > 5.0) {
        $steps[] = sprintf('The refund rate is %s%% of takings. Run refund_rate with compare:true to see whether that is where it has been.', (string) $refunds['value_rate_percent']);
    }
    if (($revenue['change']['direction'] ?? '') === 'down') {
        $steps[] = 'Net revenue is down against the previous period. top_sellers over both windows is the cheapest way to see whether it is one product or the whole shop.';
    }
    if ($steps === []) {
        $steps[] = 'Nothing here needs acting on: revenue is holding, stock is not empty and the refund rate is unremarkable.';
    }

    return nibwp_wcpro_ok(
        'store_health',
        sprintf(
            '%s net revenue from %d order(s), %s refunded, %s new customer(s), %s — %s.',
            (string) $revenue['net_revenue'],
            (int) $revenue['orders'],
            (string) $refunds['value_rate_percent'] . '%',
            $customers_block['new_customers'] === null ? 'unknown' : (string) $customers_block['new_customers'],
            (string) $period['resolved'],
            $mixed ? 'figures from more than one source, each labelled' : sprintf('figures from %s', $headline),
        ),
        nibwp_wcpro_analytics_provenance(
            $headline,
            sprintf(
                'The headline revenue figures came from %s. Every other figure in this response carries its own source, because stock is never read from the analytics tables and a summary that claimed one provenance for all six would be wrong about at least one.',
                $headline,
            ),
        ) + [
            'period' => $period,
            'mixed_sources' => $mixed,
            'sources_used'  => $unique_sources,
            'result' => [
                'revenue'   => $revenue,
                'refunds'   => $refunds,
                'stock'     => $stock_block,
                'top_seller' => $top_block,
                'customers' => $customers_block,
            ],
            'cross_check' => $current['cross_check'],
            'disputed'    => $disputed,
            'definitions' => nibwp_wcpro_analytics_definitions(),
            'next_steps'  => $steps,
        ],
    );
}
