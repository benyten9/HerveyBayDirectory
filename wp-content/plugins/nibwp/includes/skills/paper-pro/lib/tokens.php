<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Paper Pro — matching the design's literals against the tokens the site already has.
 *
 * A design hands over values: #1a7f5a, 20px, 700. A site that has a design system
 * hands over names: --primary, --text-l. Converting literally is how a page ends up
 * looking right on the day it was built and wrong the day the palette changes,
 * because nothing in it is connected to the palette at all.
 *
 * Matching is by VALUE, not by name, and that is the only way it can work: the
 * designer's layer called "Brand Green" and the site's token called --primary have
 * no relationship except that they resolve to the same colour. So colours are
 * normalised to a comparable form first — #abc and #AABBCC and rgb(170,187,204) are
 * one colour — and sizes to a number and a unit, because 20px and 20.0px and 20 PX
 * are one size.
 *
 * What this file does NOT do is decide. It reports: these literals have a token,
 * these do not, here is the token to use. Swapping them is the emitter's job, and
 * a literal with no token is often correct — a one-off accent does not need to join
 * the design system to be allowed on the page.
 */

/**
 * Normalise a colour to lowercase #rrggbb, or #rrggbbaa when it is translucent.
 * Returns null for anything that is not a colour this can compare — a gradient, a
 * CSS variable, a keyword. Those are reported as-is rather than guessed at.
 */
function nibwp_paper_pro_normalise_color(string $value): ?string
{
    $value = strtolower(trim($value));
    if ($value === '') {
        return null;
    }

    if (preg_match('/^#([0-9a-f]{3,8})$/', $value, $m) === 1) {
        $hex = $m[1];
        if (strlen($hex) === 3) {
            return '#' . $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) === 4) {
            return '#' . $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
        }
        if (strlen($hex) === 6 || strlen($hex) === 8) {
            // An opaque colour written with an alpha channel is the same colour as
            // the one written without, and the design tools write both.
            if (strlen($hex) === 8 && substr($hex, 6) === 'ff') {
                return '#' . substr($hex, 0, 6);
            }

            return '#' . $hex;
        }

        return null;
    }

    if (preg_match('/^rgba?\(\s*([0-9.]+)[\s,]+([0-9.]+)[\s,]+([0-9.]+)\s*(?:[,\/]\s*([0-9.%]+)\s*)?\)$/', $value, $m) === 1) {
        $rgb = sprintf('#%02x%02x%02x', (int) round((float) $m[1]), (int) round((float) $m[2]), (int) round((float) $m[3]));
        if (!isset($m[4]) || $m[4] === '') {
            return $rgb;
        }
        $alpha = $m[4];
        $a = str_ends_with($alpha, '%') ? ((float) rtrim($alpha, '%')) / 100 : (float) $alpha;
        if ($a >= 1) {
            return $rgb;
        }

        return $rgb . sprintf('%02x', (int) round(max(0.0, $a) * 255));
    }

    return null;
}

/**
 * Normalise a length to [number, unit], or null when it is not a plain length —
 * calc(), a variable, a percentage of something unknown. A percentage IS returned,
 * because a token can be one.
 *
 * @return array{0:float,1:string}|null
 */
function nibwp_paper_pro_normalise_size(string $value): ?array
{
    $value = strtolower(trim($value));
    if ($value === '') {
        return null;
    }
    if (preg_match('/^(-?[0-9]*\.?[0-9]+)\s*(px|rem|em|%|pt|vw|vh)?$/', $value, $m) !== 1) {
        return null;
    }
    $unit = $m[2] ?? '';
    if ($unit === '') {
        // A bare number in a font-size is px in every tool that exports one.
        $unit = 'px';
    }

    return [round((float) $m[1], 4), $unit];
}

/**
 * The CSS properties worth matching, and what kind of value each one holds.
 *
 * @return array<string,string>
 */
function nibwp_paper_pro_token_properties(): array
{
    return [
        'color'            => 'color',
        'background'       => 'color',
        'background-color' => 'color',
        'border-color'     => 'color',
        'fill'             => 'color',
        'font-size'        => 'size',
        'gap'              => 'size',
        'border-radius'    => 'size',
    ];
}

/**
 * Flatten whatever shape the site's tokens arrived in into name => normalised value.
 *
 * The integration's list_tokens is free to return a map or a list of records; both
 * are accepted here so that a change on that side is not a silent mismatch where
 * every literal suddenly has no token.
 *
 * @param array<mixed> $site_tokens
 * @return array{colors:array<string,string>,sizes:array<string,array{0:float,1:string}>,count:int}
 */
function nibwp_paper_pro_index_site_tokens(array $site_tokens): array
{
    $colors = [];
    $sizes = [];
    $count = 0;

    $flat = [];
    foreach ($site_tokens as $key => $entry) {
        if (is_array($entry)) {
            $name = (string) ($entry['name'] ?? $entry['token'] ?? $entry['slug'] ?? (is_string($key) ? $key : ''));
            $value = $entry['value'] ?? $entry['color'] ?? $entry['size'] ?? null;
            if ($name !== '' && (is_string($value) || is_int($value) || is_float($value))) {
                $flat[$name] = (string) $value;
            }
            continue;
        }
        if (is_string($key) && (is_string($entry) || is_int($entry) || is_float($entry))) {
            $flat[$key] = (string) $entry;
        }
    }

    foreach ($flat as $name => $value) {
        $count++;
        $color = nibwp_paper_pro_normalise_color($value);
        if ($color !== null) {
            // First token to claim a value wins, so a palette with two names for
            // the same colour reports the one the site listed first rather than
            // flip-flopping between them from one conversion to the next.
            if (!isset($colors[$color])) {
                $colors[$color] = (string) $name;
            }
            continue;
        }
        $size = nibwp_paper_pro_normalise_size($value);
        if ($size !== null) {
            $key = $size[0] . $size[1];
            if (!isset($sizes[$key])) {
                $sizes[$key] = ['name' => (string) $name, 'size' => $size];
            }
        }
    }

    return ['colors' => $colors, 'sizes' => $sizes, 'count' => $count];
}

/**
 * Match the design's literal colours and sizes against the site's tokens.
 *
 * @param array<string,mixed> $design
 * @param array<mixed>        $site_tokens
 * @return array{matched:array<int,array<string,mixed>>,unmatched:array<int,array<string,mixed>>,site_token_count:int,literal_count:int,recommendations:array<int,array<string,string>>}
 */
function nibwp_paper_pro_match_tokens(array $design, array $site_tokens): array
{
    $index = nibwp_paper_pro_index_site_tokens($site_tokens);
    $literals = [];

    $root = $design['root'] ?? null;
    if (is_array($root)) {
        nibwp_paper_pro_collect_literals($root, 'root', $literals);
    }

    $matched = [];
    $unmatched = [];

    foreach ($literals as $signature => $record) {
        $kind = $record['kind'];
        $token = null;

        if ($kind === 'color') {
            $normal = nibwp_paper_pro_normalise_color($record['value']);
            if ($normal !== null && isset($index['colors'][$normal])) {
                $token = $index['colors'][$normal];
            }
        } else {
            $normal = nibwp_paper_pro_normalise_size($record['value']);
            if ($normal !== null && isset($index['sizes'][$normal[0] . $normal[1]])) {
                $token = $index['sizes'][$normal[0] . $normal[1]]['name'];
            }
        }

        $entry = [
            'property' => $record['property'],
            'value'    => $record['value'],
            'kind'     => $kind,
            'uses'     => (int) $record['uses'],
            'paths'    => $record['paths'],
        ];

        if ($token !== null) {
            $entry['token'] = $token;
            $entry['use_instead'] = sprintf('var(%s, %s)', nibwp_paper_pro_css_var($token), $record['value']);
            $matched[] = $entry;
            continue;
        }

        $unmatched[] = $entry;
        unset($signature);
    }

    usort($matched, static fn (array $a, array $b): int => $b['uses'] <=> $a['uses']);
    usort($unmatched, static fn (array $a, array $b): int => $b['uses'] <=> $a['uses']);

    $recommendations = [];
    if ($index['count'] === 0) {
        $recommendations[] = [
            'rule'    => 'no_site_tokens',
            'message' => __('No site tokens were given, so every colour and size in this design counts as unmatched. Read them with the integration\'s list_tokens action before planning — matching is the difference between a page that follows the palette and a page that happens to look like it.', 'nibwp'),
        ];
    }
    if ($matched !== []) {
        $recommendations[] = [
            'rule'    => 'use_the_tokens',
            'message' => sprintf(
                /* translators: %d: how many literals have a token */
                __('%d literal value(s) in this design already have a token on this site. Emit the token with the literal as its fallback, not the literal on its own.', 'nibwp'),
                count($matched)
            ),
        ];
    }
    $heavy = 0;
    foreach ($unmatched as $entry) {
        if ($entry['uses'] >= 3) {
            $heavy++;
        }
    }
    if ($heavy > 0) {
        $recommendations[] = [
            'rule'    => 'candidate_new_tokens',
            'message' => sprintf(
                /* translators: %d: how many unmatched values are used three or more times */
                __('%d value(s) with no token are used three times or more. Those are the ones worth adding to the token system — a one-off accent is fine as a literal.', 'nibwp'),
                $heavy
            ),
        ];
    }

    return [
        'matched'          => $matched,
        'unmatched'        => $unmatched,
        'site_token_count' => $index['count'],
        'literal_count'    => count($literals),
        'recommendations'  => $recommendations,
    ];
}

/**
 * The CSS custom-property name for a token, whatever form the site reported it in.
 */
function nibwp_paper_pro_css_var(string $token): string
{
    $token = trim($token);
    if (str_starts_with($token, '--')) {
        return $token;
    }

    return '--' . ltrim($token, '-');
}

/**
 * Walk the tree and gather every literal colour and size, with where it was used.
 *
 * @param array<string,mixed> $node
 * @param array<string,array<string,mixed>> $literals
 */
function nibwp_paper_pro_collect_literals(array $node, string $path, array &$literals): void
{
    $properties = nibwp_paper_pro_token_properties();

    foreach (['style', 'layout'] as $bag) {
        if (!is_array($node[$bag] ?? null)) {
            continue;
        }
        foreach ($node[$bag] as $property => $value) {
            if (!is_string($property) || !isset($properties[$property])) {
                continue;
            }
            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                continue;
            }
            $value = (string) $value;
            $kind = $properties[$property];
            // A value that is already a variable is already connected to the token
            // system; reporting it as an unmatched literal would be advice to do
            // what has been done.
            if (str_contains($value, 'var(')) {
                continue;
            }
            if ($kind === 'color' && nibwp_paper_pro_normalise_color($value) === null) {
                continue; // A gradient or a keyword — nothing to match it against.
            }
            if ($kind === 'size' && nibwp_paper_pro_normalise_size($value) === null) {
                continue;
            }
            $signature = $kind . '|' . $property . '|' . strtolower($value);
            if (!isset($literals[$signature])) {
                $literals[$signature] = ['property' => $property, 'value' => $value, 'kind' => $kind, 'uses' => 0, 'paths' => []];
            }
            $literals[$signature]['uses']++;
            // Every use is counted; only the first few are listed. A colour used
            // two hundred times does not need two hundred paths to make the point.
            if (count($literals[$signature]['paths']) < 8) {
                $literals[$signature]['paths'][] = $path;
            }
        }
    }

    $index = 0;
    foreach ((array) ($node['children'] ?? []) as $child) {
        if (is_array($child)) {
            nibwp_paper_pro_collect_literals($child, $path . '.children[' . $index . ']', $literals);
        }
        $index++;
    }
}
