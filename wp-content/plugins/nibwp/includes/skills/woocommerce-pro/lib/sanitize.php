<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * WooCommerce Pro — boundary coercion.
 *
 * Money, stock and tax percentages arrive from a model as strings that look
 * right. Every one of them is validated here rather than cast, because a cast
 * turns "one ninety-nine" into 0 and sells stock for nothing, and PHP will not
 * mention it. Each function returns the value to hand a setter, or a WP_Error —
 * never a guess.
 *
 * Nothing in this file touches WooCommerce, so it is usable by any ability in
 * the skill and testable without a store.
 */

require_once __DIR__ . '/guard.php';

/**
 * Clamp a per_page/page pair to the house window.
 *
 * @return array{per_page: int, page: int, offset: int}
 */
function nibwp_wcpro_paginate(array $input, int $default = 20, int $max = 200): array
{
    $per_page = min(max((int) ($input['per_page'] ?? $default), 1), $max);
    $page = max((int) ($input['page'] ?? 1), 1);

    return ['per_page' => $per_page, 'page' => $page, 'offset' => ($page - 1) * $per_page];
}

/** A checkbox. Accepts the three spellings WooCommerce and JSON between them use. */
function nibwp_wcpro_bool(mixed $value, bool $default = false): bool
{
    if ($value === null) {
        return $default;
    }
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (int) $value === 1;
    }

    return in_array(strtolower(trim((string) $value)), ['yes', 'true', '1', 'on'], true);
}

/** The same, in the spelling WooCommerce stores in an option row. */
function nibwp_wcpro_yesno(mixed $value, bool $default = false): string
{
    return nibwp_wcpro_bool($value, $default) ? 'yes' : 'no';
}

/**
 * A price-shaped string. '' clears the price, which is legal and meaningful.
 */
function nibwp_wcpro_price(mixed $value, string $field): string|WP_Error
{
    if ($value === null) {
        return '';
    }

    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }
    if (!is_numeric($raw)) {
        return nibwp_wcpro_err('invalid_price', sprintf('%s must be a number, or an empty string to clear it — got "%s".', $field, $raw), 422);
    }
    if ((float) $raw < 0) {
        return nibwp_wcpro_err('invalid_price', sprintf('%s cannot be negative.', $field), 422);
    }

    return $raw;
}

/** A tax percentage. Negative rates exist nowhere; above 100 is a typo, not a rate. */
function nibwp_wcpro_rate(mixed $value, string $field): string|WP_Error
{
    $raw = trim((string) ($value ?? ''));
    if ($raw === '') {
        return nibwp_wcpro_err('missing_rate', sprintf('%s is required.', $field), 422);
    }
    if (!is_numeric($raw)) {
        return nibwp_wcpro_err('invalid_rate', sprintf('%s must be a number of percent, e.g. "21.0000" — got "%s".', $field, $raw), 422);
    }
    $number = (float) $raw;
    if ($number < 0 || $number > 100) {
        return nibwp_wcpro_err('invalid_rate', sprintf('%s must be between 0 and 100 percent, got %s.', $field, $raw), 422);
    }

    return number_format($number, 4, '.', '');
}

/** A non-negative decimal, for weight and dimensions. */
function nibwp_wcpro_measure(mixed $value, string $field): string|WP_Error
{
    if ($value === null) {
        return '';
    }
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }
    if (!is_numeric($raw) || (float) $raw < 0) {
        return nibwp_wcpro_err('invalid_measure', sprintf('%s must be a non-negative number or an empty string.', $field), 422);
    }

    return $raw;
}

/** One of a fixed set, or a refusal that names the set. */
function nibwp_wcpro_enum(mixed $value, array $allowed, string $field, ?string $default = null): string|WP_Error
{
    $raw = trim((string) ($value ?? ''));
    if ($raw === '' && $default !== null) {
        return $default;
    }
    if (!in_array($raw, $allowed, true)) {
        return nibwp_wcpro_err(
            'invalid_value',
            sprintf('%s must be one of: %s — got "%s".', $field, implode(', ', $allowed), $raw),
            422,
        );
    }

    return $raw;
}

/** Plain text, trimmed and stripped the way WordPress strips a text field. */
function nibwp_wcpro_text(mixed $value, int $max = 0): string
{
    $text = function_exists('sanitize_text_field')
        ? sanitize_text_field((string) ($value ?? ''))
        : trim(strip_tags((string) ($value ?? '')));

    return $max > 0 ? substr($text, 0, $max) : $text;
}

/**
 * A list of post ids. Accepts an array or a comma-separated string, because both
 * turn up depending on which client is talking.
 *
 * @return array<int, int>
 */
function nibwp_wcpro_ids(mixed $value): array
{
    if ($value === null || $value === '') {
        return [];
    }
    $parts = is_array($value) ? $value : explode(',', (string) $value);
    $ids = [];
    foreach ($parts as $part) {
        $id = (int) trim((string) $part);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

/**
 * A country, or a country:state pair, in the spelling WooCommerce stores.
 *
 * "NL", "US:CA". Anything else is refused rather than normalised, because a
 * wrong country silently stops tax and shipping matching and nothing complains.
 */
function nibwp_wcpro_country(mixed $value, string $field): string|WP_Error
{
    $raw = strtoupper(trim((string) ($value ?? '')));
    if ($raw === '') {
        return nibwp_wcpro_err('missing_country', sprintf('%s is required, as an ISO country code (e.g. NL) or country:state (e.g. US:CA).', $field), 422);
    }
    if (!preg_match('/^[A-Z]{2}(?::[A-Z0-9]{1,10})?$/', $raw)) {
        return nibwp_wcpro_err(
            'invalid_country',
            sprintf('%s must be an ISO country code, optionally with a state — e.g. NL or US:CA. Got "%s".', $field, $raw),
            422,
        );
    }

    return $raw;
}

/**
 * An email address, asked of WordPress.
 *
 * is_email() is stricter than a regex in places and laxer in others, and what
 * matters is that an address accepted here is one WordPress will still accept
 * when the time comes to send to it. The filter_var fallback is there so
 * validation can never quietly become a no-op in a context without WordPress.
 */
function nibwp_wcpro_is_email(mixed $address): bool
{
    $raw = trim((string) ($address ?? ''));
    if ($raw === '') {
        return false;
    }
    if (function_exists('is_email')) {
        return (bool) is_email($raw);
    }

    return filter_var($raw, FILTER_VALIDATE_EMAIL) !== false;
}

/** A three-letter currency code. */
function nibwp_wcpro_currency(mixed $value, string $field): string|WP_Error
{
    $raw = strtoupper(trim((string) ($value ?? '')));
    if (!preg_match('/^[A-Z]{3}$/', $raw)) {
        return nibwp_wcpro_err('invalid_currency', sprintf('%s must be a three-letter ISO currency code, e.g. EUR. Got "%s".', $field, $raw), 422);
    }

    return $raw;
}

/**
 * A date, in either of the two spellings WooCommerce accepts.
 *
 * Returned as given so the caller can hand it to a setter that expects a
 * date-only string for a sale window and a full timestamp for a schedule.
 */
function nibwp_wcpro_date(mixed $value, string $field): string|WP_Error
{
    $raw = trim((string) ($value ?? ''));
    if ($raw === '') {
        return '';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $raw)) {
        return nibwp_wcpro_err('invalid_date', sprintf('%s must be YYYY-MM-DD or YYYY-MM-DD HH:MM:SS, got "%s".', $field, $raw), 422);
    }
    [$y, $m, $d] = array_map('intval', explode('-', substr($raw, 0, 10)));
    if (!checkdate($m, $d, $y)) {
        return nibwp_wcpro_err('invalid_date', sprintf('%s is not a real date: "%s".', $field, $raw), 422);
    }

    return $raw;
}

/**
 * An outbound http(s) URL — a webhook target, an image to sideload, a link on an
 * external product.
 *
 * Only http and https: a javascript: or data: URL stored on a product renders
 * into the shop, and a file:// one handed to a sideload reads the server's disk.
 */
function nibwp_wcpro_url(mixed $value, string $field): string|WP_Error
{
    $raw = trim((string) ($value ?? ''));
    if ($raw === '') {
        return nibwp_wcpro_err('missing_url', sprintf('%s is required.', $field), 422);
    }
    $clean = function_exists('esc_url_raw') ? esc_url_raw($raw) : $raw;
    $scheme = strtolower((string) parse_url($clean, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true) || parse_url($clean, PHP_URL_HOST) === null) {
        return nibwp_wcpro_err('invalid_url', sprintf('%s must be an absolute http:// or https:// URL, got "%s".', $field, $raw), 422);
    }

    return $clean;
}

/**
 * Apply a percentage or a fixed delta to a price.
 *
 * Kept here, away from the catalogue handler, because it is the one calculation
 * in the skill a rounding mistake would quietly ship across a whole catalogue.
 * A result below zero is clamped to zero rather than refused: the caller asked
 * for "20% off" on a 5.00 item and meant 4.00, not an error.
 */
function nibwp_wcpro_apply_price_change(string $price, string $mode, float $amount, int $decimals = 2): string
{
    if (trim($price) === '' || !is_numeric($price)) {
        return '';
    }

    $current = (float) $price;
    $next = match ($mode) {
        'increase_percent' => $current * (1 + ($amount / 100)),
        'decrease_percent' => $current * (1 - ($amount / 100)),
        'increase_fixed'   => $current + $amount,
        'decrease_fixed'   => $current - $amount,
        'set_fixed'        => $amount,
        default            => $current,
    };

    return number_format(max(0.0, round($next, $decimals)), $decimals, '.', '');
}
