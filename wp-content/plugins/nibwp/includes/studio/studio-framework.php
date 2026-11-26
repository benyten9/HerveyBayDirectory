<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * NibWP's CSS framework: a few settings in, a full token set out.
 *
 * The token names are the ones most builder sites already speak (--space-m,
 * --text-l, --primary-light, --h2, --btn-radius…), so CSS the Builder writes
 * once works with whatever framework a site runs. The values are generated
 * here from NibWP's own settings. Switched on, they are printed as
 * `html:root`, which outranks a plain `:root` from any other framework; off,
 * nothing is printed and the Builder's fallbacks carry the page.
 *
 * Option nibwp_framework: on (bool), set (the inputs), over (token => value,
 * typed by hand). The printed CSS is cached in nibwp_framework_css.
 */

const NIBWP_FW_COLORS = ['primary', 'secondary', 'tertiary', 'accent', 'base', 'neutral', 'success', 'warning', 'danger', 'info'];

/** The inputs, with values that look good on a bare site. */
function nibwp_framework_defaults(): array
{
    return [
        'primary' => '#2563eb',
        'secondary' => '#7c3aed',
        'tertiary' => '#db2777',
        'accent' => '#f59e0b',
        'base' => '#1e2433',
        'neutral' => '#6b7080',
        'success' => '#16a34a',
        'warning' => '#eab308',
        'danger' => '#dc2626',
        'info' => '#0891b2',
        'vp_min' => 360,
        'vp_max' => 1280,
        'content_width' => 1280,
        'text_min' => 16,
        'text_max' => 18,
        'text_ratio_min' => 1.2,
        'text_ratio_max' => 1.25,
        'h_ratio_min' => 1.2,
        'h_ratio_max' => 1.333,
        'space_min' => 24,
        'space_max' => 32,
        'space_ratio_min' => 1.333,
        'space_ratio_max' => 1.5,
        'section_min' => 48,
        'section_max' => 96,
        'gutter_min' => 16,
        'gutter_max' => 48,
        'radius' => 8,
        'radius_ratio' => 1.5,
        'font_text' => 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
        'font_heading' => 'inherit',
        'heading_weight' => 700,
        'btn_weight' => 600,
        'btn_pad_y' => 0.75,
        'btn_pad_x' => 1.5,
        'btn_radius' => 8,
    ];
}

/** Each number input: [min, max]. */
function nibwp_framework_ranges(): array
{
    return [
        'vp_min' => [240, 800], 'vp_max' => [800, 2560], 'content_width' => [600, 2560],
        'text_min' => [10, 32], 'text_max' => [10, 40], 'text_ratio_min' => [1, 2], 'text_ratio_max' => [1, 2],
        'h_ratio_min' => [1, 2], 'h_ratio_max' => [1, 2],
        'space_min' => [4, 96], 'space_max' => [4, 128], 'space_ratio_min' => [1, 2.5], 'space_ratio_max' => [1, 2.5],
        'section_min' => [8, 240], 'section_max' => [8, 320], 'gutter_min' => [0, 120], 'gutter_max' => [0, 160],
        'radius' => [0, 64], 'radius_ratio' => [1, 3], 'heading_weight' => [100, 900], 'btn_weight' => [100, 900],
        'btn_pad_y' => [0, 3], 'btn_pad_x' => [0, 5], 'btn_radius' => [0, 999],
    ];
}

/** Inputs as typed, made safe: unknown keys dropped, numbers in range, colours as hex. */
function nibwp_framework_clean_set($raw): array
{
    $def = nibwp_framework_defaults();
    $raw = is_array($raw) ? $raw : [];
    $out = $def;
    foreach (NIBWP_FW_COLORS as $c) {
        $v = strtolower(trim((string) ($raw[$c] ?? '')));
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $v)) {
            $out[$c] = strlen($v) === 4 ? '#' . $v[1] . $v[1] . $v[2] . $v[2] . $v[3] . $v[3] : $v;
        }
    }
    foreach (nibwp_framework_ranges() as $k => $r) {
        if (isset($raw[$k]) && is_numeric($raw[$k])) {
            $out[$k] = max($r[0], min($r[1], round((float) $raw[$k], 3)));
        }
    }
    foreach (['font_text', 'font_heading'] as $k) {
        $v = trim(preg_replace('/[^\w\s,"\'.-]/u', '', (string) ($raw[$k] ?? '')) ?? '');
        if ($v !== '') {
            $out[$k] = mb_substr($v, 0, 200);
        }
    }
    if ($out['vp_max'] <= $out['vp_min']) {
        $out['vp_max'] = $out['vp_min'] + 400;
    }

    return $out;
}

/** Hand-typed token values: a token name and one CSS value, nothing that could end the rule. */
function nibwp_framework_clean_over($raw): array
{
    $out = [];
    foreach (is_array($raw) ? array_slice($raw, 0, 600, true) : [] as $k => $v) {
        $k = '--' . ltrim(strtolower(trim((string) $k)), '-');
        $v = trim((string) $v);
        if (!preg_match('/^--[a-z0-9-]{1,60}$/', $k) || $v === '' || preg_match('/[;{}<>]|\/\*/', $v)) {
            continue;
        }
        $out[$k] = mb_substr($v, 0, 300);
    }

    return $out;
}

/** @return array{on: bool, set: array, over: array} */
function nibwp_framework_state(): array
{
    $o = get_option('nibwp_framework', []);
    $o = is_array($o) ? $o : [];

    return [
        'on' => !empty($o['on']),
        'set' => nibwp_framework_clean_set($o['set'] ?? []),
        'over' => nibwp_framework_clean_over($o['over'] ?? []),
    ];
}

function nibwp_framework_save(array $state): array
{
    $clean = [
        'on' => !empty($state['on']),
        'set' => nibwp_framework_clean_set($state['set'] ?? []),
        'over' => nibwp_framework_clean_over($state['over'] ?? []),
    ];
    update_option('nibwp_framework', $clean, true);
    update_option('nibwp_framework_css', ['v' => nibwp_framework_version(), 'css' => nibwp_framework_css($clean['set'], $clean['over'])], true);

    return $clean;
}

/* ------------------------------------------------------------- colour -- */

/** #rrggbb as OKLCH: [lightness 0..1, chroma, hue in degrees]. */
function nibwp_framework_oklch(string $hex): array
{
    $hex = ltrim($hex, '#');
    $lin = [];
    foreach ([0, 2, 4] as $i) {
        $c = hexdec(substr($hex, $i, 2)) / 255;
        $lin[] = $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }
    [$r, $g, $b] = $lin;
    $l = (0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b) ** (1 / 3);
    $m = (0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b) ** (1 / 3);
    $s = (0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b) ** (1 / 3);
    $L = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
    $A = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
    $B = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;
    $h = rad2deg(atan2($B, $A));

    return [$L, sqrt($A * $A + $B * $B), $h < 0 ? $h + 360 : $h];
}

function nibwp_framework_oklch_css(float $l, float $c, float $h, ?float $alpha = null): string
{
    $v = sprintf('oklch(%s %s %s', nibwp_framework_num(max(0, min(1, $l))), nibwp_framework_num(max(0, $c)), nibwp_framework_num($h, 2));

    return $v . ($alpha === null ? ')' : ' / ' . nibwp_framework_num($alpha, 2) . ')');
}

function nibwp_framework_num(float $n, int $places = 4): string
{
    $s = rtrim(rtrim(number_format($n, $places, '.', ''), '0'), '.');

    return $s === '-0' || $s === '' ? '0' : $s;
}

/** A colour and its shades: lightness targets fixed, chroma eased off toward the ends. */
function nibwp_framework_shades(string $name, string $hex): array
{
    [$l, $c, $h] = nibwp_framework_oklch($hex);
    $out = ['--' . $name => nibwp_framework_oklch_css($l, $c, $h)];
    $out['--' . $name . '-hover'] = nibwp_framework_oklch_css($l > 0.6 ? $l - 0.07 : $l + 0.08, $c, $h);
    foreach (['ultra-light' => [0.97, 0.12], 'light' => [0.92, 0.3], 'semi-light' => [0.8, 0.7], 'semi-dark' => [0.52, 1], 'dark' => [0.4, 0.8], 'ultra-dark' => [0.25, 0.5]] as $shade => [$tl, $tc]) {
        $out['--' . $name . '-' . $shade] = nibwp_framework_oklch_css($tl, $c * $tc, $h);
    }
    for ($t = 10; $t <= 90; $t += 10) {
        $out['--' . $name . '-trans-' . $t] = nibwp_framework_oklch_css($l, $c, $h, $t / 100);
    }

    return $out;
}

/* -------------------------------------------------------------- sizes -- */

/** A size that grows from $min px at the small screen to $max px at the large one. */
function nibwp_framework_fluid(float $min, float $max, float $vpMin, float $vpMax): string
{
    $rem = static fn(float $px): string => nibwp_framework_num($px / 16) . 'rem';
    if (abs($max - $min) < 0.01) {
        return $rem($min);
    }
    $slope = ($max - $min) / ($vpMax - $vpMin);
    $base = ($min - $slope * $vpMin) / 16;

    return sprintf('clamp(%s, calc(%svw + %srem), %s)', $rem(min($min, $max)), nibwp_framework_num($slope * 100), nibwp_framework_num($base), $rem(max($min, $max)));
}

/**
 * A scale: each step's [min, max] px from a base and two ratios, plus the
 * "-to-" pairs (the bigger step on large screens, the smaller on small ones).
 *
 * @param array<string, int> $steps name => power of the ratio, biggest first
 */
function nibwp_framework_scale(string $prefix, array $steps, float $min, float $max, float $rMin, float $rMax, array $set): array
{
    $px = [];
    foreach ($steps as $name => $pow) {
        $px[$name] = [$min * ($rMin ** $pow), $max * ($rMax ** $pow)];
    }
    $out = [];
    $names = array_keys($px);
    foreach ($names as $big) {
        $out['--' . $prefix . $big] = nibwp_framework_fluid($px[$big][0], $px[$big][1], $set['vp_min'], $set['vp_max']);
    }
    foreach ($names as $i => $big) {
        foreach (array_slice($names, $i + 1) as $small) {
            $out['--' . $prefix . $big . '-to-' . $small] = nibwp_framework_fluid($px[$small][0], $px[$big][1], $set['vp_min'], $set['vp_max']);
        }
    }

    return $out;
}

/* ------------------------------------------------------------ tokens -- */

/**
 * Every token, name => value, before hand-typed overrides.
 *
 * @return array<string, string>
 */
function nibwp_framework_tokens(array $set): array
{
    $s = nibwp_framework_clean_set($set);
    $t = [];

    foreach (NIBWP_FW_COLORS as $c) {
        $t += nibwp_framework_shades($c, $s[$c]);
    }
    $t['--white'] = '#fff';
    $t['--black'] = '#000';
    for ($a = 10; $a <= 90; $a += 10) {
        $t['--white-trans-' . $a] = 'rgb(255 255 255 / ' . nibwp_framework_num($a / 100, 2) . ')';
        $t['--black-trans-' . $a] = 'rgb(0 0 0 / ' . nibwp_framework_num($a / 100, 2) . ')';
    }

    $t['--root-font-size'] = '100%';
    $t['--content-width'] = nibwp_framework_num($s['content_width'] / 16) . 'rem';
    $t['--content-width-safe'] = 'min(var(--content-width), calc(100% - var(--gutter) * 2))';
    $t['--focus-color'] = 'var(--primary)';
    $t['--focus-width'] = '2px';
    $t['--focus-offset'] = '2px';

    $t += nibwp_framework_scale('text-', ['xxl' => 3, 'xl' => 2, 'l' => 1, 'm' => 0, 's' => -1, 'xs' => -2], $s['text_min'], $s['text_max'], $s['text_ratio_min'], $s['text_ratio_max'], $s);
    foreach (['xxl', 'xl', 'l', 'm', 's', 'xs'] as $n) {
        $t['--text-' . $n . '-max-width'] = '100%';
    }
    $t['--text-m-line-height'] = 'calc(6px + 2ex)';
    $t['--base-text-lh'] = 'calc(6px + 2ex)';
    $t['--text-line-height'] = 'calc(6px + 2ex)';
    $t['--text-color'] = 'var(--text-dark)';
    $t['--text-text-wrap'] = 'pretty';
    $t['--text-font-family'] = $s['font_text'];

    $heads = nibwp_framework_scale('h', ['1' => 3, '2' => 2, '3' => 1, '4' => 0, '5' => -1, '6' => -2], $s['text_min'], $s['text_max'], $s['h_ratio_min'], $s['h_ratio_max'], $s);
    foreach ($heads as $k => $v) {
        $t[preg_replace('/^--h(\d)-to-(\d)$/', '--h$1-to-h$2', $k)] = $v;
    }
    for ($i = 1; $i <= 6; $i++) {
        $t['--h' . $i . '-max-width'] = '100%';
    }
    $t['--h4-line-height'] = 'calc(4px + 2ex)';
    $t['--heading-line-height'] = 'calc(4px + 2ex)';
    $t['--base-heading-lh'] = 'calc(4px + 2ex)';
    $t['--heading-font-weight'] = (string) (int) $s['heading_weight'];
    $t['--heading-text-wrap'] = 'balance';
    $t['--heading-font-family'] = $s['font_heading'];

    $t += nibwp_framework_scale('space-', ['xxl' => 3, 'xl' => 2, 'l' => 1, 'm' => 0, 's' => -1, 'xs' => -2], $s['space_min'], $s['space_max'], $s['space_ratio_min'], $s['space_ratio_max'], $s);
    $t += nibwp_framework_scale('section-space-', ['xxl' => 3, 'xl' => 2, 'l' => 1, 'm' => 0, 's' => -1, 'xs' => -2], $s['section_min'], $s['section_max'], $s['space_ratio_min'], $s['space_ratio_max'], $s);
    $t['--section-padding-block'] = 'var(--section-space-m)';
    $t['--gutter'] = nibwp_framework_fluid($s['gutter_min'], $s['gutter_max'], $s['vp_min'], $s['vp_max']);
    $t['--content-gap'] = 'var(--space-m)';
    $t['--container-gap'] = 'var(--space-xl)';
    $t['--grid-gap'] = 'var(--space-m)';

    for ($w = 10; $w <= 90; $w += 10) {
        $t['--width-' . $w] = 'calc(var(--content-width) * ' . nibwp_framework_num($w / 100, 1) . ')';
    }
    $t['--width-full'] = 'var(--content-width)';

    for ($g = 1; $g <= 12; $g++) {
        $t['--grid-' . $g] = 'repeat(' . $g . ', minmax(0, 1fr))';
    }
    foreach (['1-2', '1-3', '2-1', '2-3', '3-1', '3-2'] as $r) {
        [$a, $b] = explode('-', $r);
        $t['--grid-' . $r] = 'minmax(0, ' . $a . 'fr) minmax(0, ' . $b . 'fr)';
    }
    for ($g = 2; $g <= 12; $g++) {
        $t['--grid-auto-' . $g] = 'repeat(auto-fit, minmax(min(100%, max(calc((var(--content-width) - ((' . $g . ' - 1) * var(--grid-gap))) / ' . $g . ') * var(--auto-grid-aggressiveness), (100% - (' . $g . ' - 1) * var(--grid-gap)) / ' . $g . ')), 1fr))';
    }
    $t['--auto-grid-aggressiveness'] = '0.7';

    $t['--header-height'] = '0px';
    $t['--admin-bar-height'] = 'var(--wp-admin--admin-bar--height, 0px)';
    $t['--offset'] = 'var(--header-height)';
    $t['--sticky-offset-custom'] = '60px';
    $t['--sticky-offset'] = 'var(--header-height, var(--sticky-offset-custom, 0))';

    $t += [
        '--paragraph-spacing' => '1em', '--heading-spacing' => '1.2em', '--list-spacing' => 'var(--paragraph-spacing)',
        '--list-indent-spacing' => '2em', '--list-item-spacing' => '0.5em', '--nested-list-indent-spacing' => '1em',
        '--flow-spacing' => 'var(--paragraph-spacing)', '--figure-spacing' => 'var(--paragraph-spacing)',
        '--figcaption-spacing' => '0.5em', '--blockquote-spacing' => 'var(--paragraph-spacing)',
    ];

    $t += [
        '--option-primary-btn-outline' => 'on', '--option-secondary-btn-outline' => 'on',
        '--btn-padding-block' => nibwp_framework_num($s['btn_pad_y'], 3) . 'em', '--btn-padding-inline' => nibwp_framework_num($s['btn_pad_x'], 3) . 'em',
        '--btn-min-width' => '8rem', '--btn-width' => 'max-content', '--btn-line-height' => '1.2', '--btn-font-size' => 'var(--text-m)',
        '--btn-font-weight' => (string) (int) $s['btn_weight'], '--btn-font-style' => 'normal', '--btn-text-decoration' => 'none',
        '--btn-text-decoration-hover' => 'none', '--btn-letter-spacing' => '0', '--btn-text-transform' => 'none',
        '--btn-border-width' => '2px', '--btn-outline-border-width' => '2px', '--btn-border-style' => 'solid',
        '--btn-border-radius' => nibwp_framework_num($s['btn_radius'], 2) . 'px', '--btn-radius' => nibwp_framework_num($s['btn_radius'], 2) . 'px',
        '--btn-justify-content' => 'center', '--btn-text-align' => 'center',
        '--link-color' => 'var(--primary)', '--link-color-hover' => 'var(--primary-hover)', '--link-transition' => 'var(--transition)',
        '--link-decoration' => 'inherit', '--link-decoration-hover' => 'inherit', '--link-underline-offset' => 'auto', '--link-weight' => 'inherit',
    ];

    $t += [
        '--card-padding' => 'var(--space-m)', '--card-gap' => 'calc(var(--content-gap) / 2)', '--card-border-width' => 'var(--border-width)',
        '--card-border-style' => 'solid', '--card-display' => 'flex', '--card-radius' => 'var(--radius-l)', '--card-heading-size' => 'var(--h4)',
        '--card-text-size' => 'var(--text-s)', '--card-button-font-size' => 'var(--text-s)', '--card-icon-size' => '64px',
        '--card-icon-padding' => 'var(--icon-padding)', '--card-icon-border-width' => 'var(--icon-border-width)',
        '--card-icon-border-style' => 'var(--icon-border-style)', '--card-icon-radius' => 'var(--icon-radius)',
        '--card-icon-background' => 'var(--icon-background)', '--card-icon-color' => 'var(--icon-color)',
        '--card-icon-border-color' => 'var(--icon-border-color)', '--card-line-height' => '1.5', '--card-avatar-radius' => 'var(--radius-circle)',
        '--card-avatar-border-width' => 'var(--border-width)', '--card-avatar-border-style' => 'var(--border-style)', '--card-avatar-size' => '120px',
        '--card-avatar-aspect-ratio' => '1', '--card-media-radius' => 'var(--radius)', '--card-media-aspect-ratio' => '1.5',
        '--card-media-object-fit' => 'cover', '--card-background' => 'var(--white)', '--card-heading-color' => 'var(--text-dark)',
        '--card-text-color' => 'var(--text-dark-muted)', '--card-link-color' => 'var(--primary)', '--card-link-color-hover' => 'var(--primary-hover)',
        '--card-button-style' => 'primary', '--card-border-color' => 'var(--border-color-dark)',
        '--col-width-s' => '13rem', '--col-width-m' => '25rem', '--col-width-l' => '38rem',
        '--col-rule-width-s' => '0.5px', '--col-rule-width-m' => '1px', '--col-rule-width-l' => '3px',
    ];

    $t += [
        '--bg-ultra-light' => 'var(--neutral-ultra-light)', '--bg-light' => 'var(--neutral-light)',
        '--bg-dark' => 'var(--base-dark)', '--bg-ultra-dark' => 'var(--base-ultra-dark)',
        '--text-light' => 'var(--white)', '--text-light-muted' => 'var(--white-trans-80)',
        '--text-dark' => 'var(--base)', '--text-dark-muted' => 'var(--base-trans-80)',
    ];
    foreach (['ultra-light' => 'dark', 'light' => 'dark', 'dark' => 'light', 'ultra-dark' => 'light'] as $bg => $ink) {
        $t['--bg-' . $bg . '-text'] = 'var(--text-' . $ink . ')';
        $t['--bg-' . $bg . '-heading'] = 'var(--text-' . $ink . ')';
        $t['--bg-' . $bg . '-button'] = 'primary';
        $t['--bg-' . $bg . '-icon'] = $ink === 'dark' ? 'light' : 'dark';
    }

    $r = (float) $s['radius'];
    $q = (float) $s['radius_ratio'];
    $t += [
        '--radius' => nibwp_framework_num($r, 2) . 'px', '--radius-50' => '50%', '--radius-circle' => '50vw', '--radius-none' => '0',
        '--radius-xs' => nibwp_framework_num($r / ($q * $q), 2) . 'px', '--radius-s' => nibwp_framework_num($r / $q, 2) . 'px',
        '--radius-m' => nibwp_framework_num($r, 2) . 'px', '--radius-l' => nibwp_framework_num($r * $q, 2) . 'px',
        '--radius-xl' => nibwp_framework_num($r * $q * $q, 2) . 'px', '--radius-xxl' => nibwp_framework_num($r * $q * $q * $q, 2) . 'px',
    ];

    $t += [
        '--border-size' => '1px', '--border-width' => '1px', '--border-style' => 'solid',
        '--border-color-dark' => 'var(--black-trans-20)', '--border-color-light' => 'var(--white-trans-20)',
        '--border' => 'var(--border-size) var(--border-style) var(--border-color-dark)',
        '--border-light' => 'var(--border-size) var(--border-style) var(--border-color-light)',
        '--border-dark' => 'var(--border-size) var(--border-style) var(--border-color-dark)',
        '--divider-size' => '1px', '--divider-style' => 'solid', '--divider-inline-size' => '100%', '--divider-gap' => 'var(--content-gap)',
        '--divider-color-dark' => 'var(--black-trans-20)', '--divider-color-light' => 'var(--white-trans-20)',
        '--divider' => 'var(--divider-size) var(--divider-style) var(--divider-color-dark)',
        '--divider-light' => 'var(--divider-size) var(--divider-style) var(--divider-color-light)',
        '--divider-dark' => 'var(--divider-size) var(--divider-style) var(--divider-color-dark)',
        '--transition-duration' => '0.3s', '--transition-timing' => 'ease-in-out', '--transition-delay' => '0s',
        '--transition' => 'var(--transition-duration) var(--transition-timing) var(--transition-delay)',
        '--icon-scheme' => 'inherit', '--icon-size' => '32px', '--icon-padding' => '0.35em', '--icon-radius' => 'var(--radius)',
        '--icon-background' => 'var(--neutral-ultra-light)', '--icon-background-hover' => 'var(--neutral-light)',
        '--icon-border-color' => 'var(--border-color-dark)', '--icon-border-color-hover' => 'inherit',
        '--icon-border-width' => 'var(--border-width)', '--icon-border-style' => 'var(--border-style)',
        '--icon-color' => 'var(--text-dark-muted)', '--icon-color-hover' => 'var(--primary)',
        '--icon-list-icon-size' => '1em', '--icon-list-gap' => '1em',
        '--icon-size-xs' => '12px', '--icon-size-s' => '16px', '--icon-size-m' => '32px', '--icon-size-l' => '64px', '--icon-size-xl' => '128px',
        '--box-shadow-m' => '0 4px 24px var(--black-trans-10)', '--box-shadow-l' => '0 8px 40px var(--black-trans-10)',
        '--box-shadow-xl' => '0 16px 64px var(--black-trans-20)', '--box-shadow-1' => '0 4px 24px var(--black-trans-10)',
        '--box-shadow-2' => '0 8px 40px var(--black-trans-10)', '--box-shadow-3' => '0 16px 64px var(--black-trans-20)',
    ];

    return $t;
}

/**
 * A light base: body text, headings, links and focus from the tokens. Under
 * :where(), so a theme's own rules still win; it only fills what nobody set.
 */
function nibwp_framework_base_css(): string
{
    $css = ':where(body){font-family:var(--text-font-family);font-size:var(--text-m);line-height:var(--text-line-height);color:var(--text-color);text-wrap:var(--text-text-wrap)}';
    $css .= ':where(h1,h2,h3,h4,h5,h6){font-family:var(--heading-font-family);font-weight:var(--heading-font-weight);line-height:var(--heading-line-height);text-wrap:var(--heading-text-wrap)}';
    for ($i = 1; $i <= 6; $i++) {
        $css .= ':where(h' . $i . '){font-size:var(--h' . $i . ')}';
    }
    $css .= ':where(a){color:var(--link-color);transition:color var(--link-transition)}:where(a:hover){color:var(--link-color-hover)}';
    $css .= ':where(:focus-visible){outline:var(--focus-width) solid var(--focus-color);outline-offset:var(--focus-offset)}';

    return $css;
}

function nibwp_framework_css(array $set, array $over = []): string
{
    $lines = [];
    foreach (array_merge(nibwp_framework_tokens($set), nibwp_framework_clean_over($over)) as $k => $v) {
        $lines[] = $k . ':' . $v;
    }

    return 'html:root{' . implode(';', $lines) . "}\n" . nibwp_framework_base_css();
}

/* ------------------------------------------------------- on the site -- */

/** Changes when this file does, so a plugin update regenerates the cached CSS. */
function nibwp_framework_version(): string
{
    return (string) filemtime(__FILE__);
}

add_action('wp_head', static function (): void {
    if (!nibwp_framework_state()['on']) {
        return;
    }
    $cache = get_option('nibwp_framework_css', []);
    $css = is_array($cache) && ($cache['v'] ?? '') === nibwp_framework_version() ? (string) ($cache['css'] ?? '') : '';
    if ($css === '') {
        $s = nibwp_framework_save(nibwp_framework_state());
        $css = nibwp_framework_css($s['set'], $s['over']);
    }
    echo '<style id="nibwp-framework">' . str_ireplace('</style', '<\/style', $css) . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- generated from cleaned settings
}, 100);

/* -------------------------------------------------------------- REST -- */

/** What the Builder's Design tab shows. */
function nibwp_framework_payload(): array
{
    $s = nibwp_framework_state();

    return [
        'on' => $s['on'],
        'set' => $s['set'],
        'over' => (object) $s['over'],
        'defaults' => nibwp_framework_defaults(),
        'tokens' => (object) nibwp_framework_tokens($s['set']),
        'css' => nibwp_framework_css($s['set'], $s['over']),
        'other' => nibwp_framework_other_active(),
    ];
}

/** Whether another token framework is active here (never named in the UI). */
function nibwp_framework_other_active(): bool
{
    if (!function_exists('nibwp_design_system_detect')) {
        return false;
    }
    $d = nibwp_design_system_detect();

    return in_array($d['system'] ?? 'none', ['acss', 'core-framework'], true);
}

add_action('rest_api_init', static function (): void {
    register_rest_route('nibwp/v1', '/studio/framework', [
        [
            'methods' => 'GET',
            'permission_callback' => static fn(): bool => current_user_can('manage_options'),
            'callback' => static fn(): WP_REST_Response => new WP_REST_Response(nibwp_framework_payload()),
        ],
        [
            'methods' => 'POST',
            'permission_callback' => static fn(): bool => current_user_can('manage_options'),
            'callback' => static function (WP_REST_Request $request): WP_REST_Response {
                $s = nibwp_framework_state();
                if ($request->has_param('on')) {
                    $s['on'] = (bool) $request->get_param('on');
                }
                if (is_array($request->get_param('set'))) {
                    $s['set'] = array_merge($s['set'], $request->get_param('set'));
                }
                if (is_array($request->get_param('over'))) {
                    $s['over'] = $request->get_param('over');
                }
                nibwp_framework_save($s);

                return new WP_REST_Response(nibwp_framework_payload());
            },
        ],
    ]);
    register_rest_route('nibwp/v1', '/studio/framework/preview', [
        'methods' => 'POST',
        'permission_callback' => static fn(): bool => current_user_can('manage_options'),
        'callback' => static function (WP_REST_Request $request): WP_REST_Response {
            $set = nibwp_framework_clean_set(array_merge(nibwp_framework_state()['set'], (array) $request->get_param('set')));

            return new WP_REST_Response([
                'css' => nibwp_framework_css($set, (array) $request->get_param('over')),
                'tokens' => (object) nibwp_framework_tokens($set),
            ]);
        },
    ]);
});

/** How the agent names and styles what it builds, with every request. */
function nibwp_framework_agent_note(): string
{
    $note = 'Name the classes you create in BEM: block, block__element (flat, never block__a__b), block--modifier; repeated items share one class. Style with tokens and a fallback, e.g. padding: var(--space-m, 24px); color: var(--primary, #2563eb); font-size: var(--text-l, 20px). Token names: --primary/--secondary/--accent/--base/--neutral with -ultra-light, -light, -semi-light, -hover, -semi-dark, -dark, -ultra-dark and -trans-10…90; --space-xs…xxl, --section-space-xs…xxl, --text-xs…xxl, --h1…--h6, --radius-xs…xxl, --gutter, --content-width, --btn-*, --card-*. No clamp() in your CSS: the tokens are already fluid.';
    if (nibwp_framework_state()['on']) {
        $note .= ' NibWP\'s own CSS framework is on for this site, so these tokens are its values: read or change them with nibwp/framework-get and nibwp/framework-set, never redefine them in page CSS.';
    }

    return $note;
}

/* --------------------------------------------------------- abilities -- */

function nibwp_framework_register_abilities(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }
    $inputs = 'Inputs: primary, secondary, tertiary, accent, base (dark text colour), neutral (greys), success, warning, danger, info as #hex; vp_min/vp_max (px, the screen widths the fluid sizes grow between); content_width (px); text_min/text_max (body text px on small/large screens); text_ratio_min/text_ratio_max (type scale, e.g. 1.2 and 1.25); h_ratio_min/h_ratio_max (heading scale); space_min/space_max (px of --space-m); space_ratio_min/space_ratio_max; section_min/section_max (px of --section-space-m); gutter_min/gutter_max (px); radius (px) and radius_ratio; font_text and font_heading (CSS font-family lists; the fonts must already load on the site); heading_weight; btn_weight, btn_pad_y and btn_pad_x (em), btn_radius (px).';

    wp_register_ability('nibwp/framework-get', [
        'label' => __('Read the NibWP CSS framework settings', 'nibwp'),
        'description' => 'Returns NibWP\'s CSS framework for this site: whether it is on, its inputs (from which every token is generated), the tokens typed by hand (overrides), and whether another token framework is also active. Tokens use the familiar names (--primary, --primary-light, --space-m, --text-l, --h2, --section-space-xl, --radius-m, --btn-radius…). ' . $inputs,
        'category' => 'visual',
        'input_schema' => ['type' => 'object', 'properties' => (object) []],
        'execute_callback' => static function (): array {
            $p = nibwp_framework_payload();
            unset($p['css'], $p['tokens']);

            return $p;
        },
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => ['mcp' => ['public' => true], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
    ]);

    wp_register_ability('nibwp/framework-set', [
        'label' => __('Change the NibWP CSS framework settings', 'nibwp'),
        'description' => 'Sets NibWP\'s CSS framework for this site and regenerates every token. Pass only what changes: settings merge into the current inputs; overrides set single tokens by hand (an empty string removes one); on switches the framework on or off (on: its values win over any other framework). Use it to set the site up from brand colours, a logo, a screenshot or another site. Afterwards tell the person what you chose; they can fine-tune it in the Builder\'s Design tab. ' . $inputs,
        'category' => 'visual',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'on' => ['type' => 'boolean', 'description' => 'Switch the framework on (true) or off (false).'],
                'settings' => ['type' => 'object', 'description' => 'Inputs to change, e.g. {"primary": "#0f766e", "text_max": 19}.'],
                'overrides' => ['type' => 'object', 'description' => 'Tokens set by hand, e.g. {"--card-background": "var(--primary-ultra-light)"}. An empty string removes the override.'],
                'reset' => ['type' => 'boolean', 'description' => 'Start from the default inputs and no overrides.'],
            ],
        ],
        'execute_callback' => static function (array $input = []): array {
            $s = nibwp_framework_state();
            if (!empty($input['reset'])) {
                $s['set'] = nibwp_framework_defaults();
                $s['over'] = [];
            }
            if (isset($input['on'])) {
                $s['on'] = (bool) $input['on'];
            }
            if (is_array($input['settings'] ?? null)) {
                $s['set'] = array_merge($s['set'], $input['settings']);
            }
            if (is_array($input['overrides'] ?? null)) {
                foreach ($input['overrides'] as $k => $v) {
                    $k = '--' . ltrim((string) $k, '-');
                    if (trim((string) $v) === '') {
                        unset($s['over'][$k]);
                    } else {
                        $s['over'][$k] = (string) $v;
                    }
                }
            }
            $saved = nibwp_framework_save($s);

            return ['on' => $saved['on'], 'settings' => $saved['set'], 'overrides' => (object) $saved['over'], 'tokens' => count(nibwp_framework_tokens($saved['set']))];
        },
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => ['mcp' => ['public' => true], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true]],
    ]);
}
