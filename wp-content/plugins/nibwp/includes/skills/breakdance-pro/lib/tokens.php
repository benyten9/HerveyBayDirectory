<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Breakdance Pro — design tokens.
 *
 * The difference between a section that looks like the site and one that merely
 * looks fine is whether it reuses what is already there. Breakdance exposes
 * three layers worth reading before anything is generated: variables (the
 * tokens), presets (saved element styling) and selectors (global classes).
 */

/**
 * Every variable defined on this site, flattened to name/value pairs.
 *
 * @return list<array{name:string, value:string, collection:string}>
 */
function nibwp_bdpro_variables(): array
{
    if (!function_exists('Breakdance\\Variables\\getVariables')) {
        return [];
    }

    try {
        $raw = \Breakdance\Variables\getVariables();
    } catch (\Throwable $e) {
        return [];
    }

    $out = [];

    $walk = static function ($node, string $collection) use (&$walk, &$out): void {
        foreach ((array) $node as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = (array) $value;
                if (isset($value['name']) || isset($value['value'])) {
                    $out[] = [
                        'name'       => (string) ($value['name'] ?? $key),
                        'value'      => (string) ($value['value'] ?? ''),
                        'collection' => $collection,
                    ];
                } else {
                    $walk($value, $collection !== '' ? $collection : (string) $key);
                }
                continue;
            }

            if (is_scalar($value)) {
                $out[] = ['name' => (string) $key, 'value' => (string) $value, 'collection' => $collection];
            }
        }
    };

    $walk($raw, '');

    return $out;
}

/** The global classes/selectors already defined, so generated markup reuses them. */
function nibwp_bdpro_selectors(): array
{
    if (!function_exists('Breakdance\\Data\\load_selectors')) {
        return [];
    }

    try {
        $selectors = \Breakdance\Data\load_selectors();
    } catch (\Throwable $e) {
        return [];
    }

    $names = [];

    $walk = static function ($node) use (&$walk, &$names): void {
        foreach ((array) $node as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = (array) $value;
                foreach (['selector', 'name', 'className', 'class'] as $field) {
                    if (!empty($value[$field]) && is_string($value[$field])) {
                        $names[] = $value[$field];
                        continue 2;
                    }
                }
                $walk($value);
            } elseif (is_string($key) && is_string($value) && str_starts_with($key, '.')) {
                $names[] = $key;
            }
        }
    };

    $walk($selectors);

    return array_values(array_unique($names));
}

/** Saved design presets, so a new element can adopt one instead of inventing styling. */
function nibwp_bdpro_presets(): array
{
    if (!function_exists('Breakdance\\Data\\load_presets')) {
        return [];
    }

    try {
        return (array) \Breakdance\Data\load_presets();
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Does this site have a usable token layer at all?
 *
 * Decides whether the skill should insist on tokens or accept literals. On a
 * site with no variables defined, refusing every hardcoded color would refuse
 * everything, which helps nobody.
 */
function nibwp_bdpro_has_token_layer(): bool
{
    return nibwp_bdpro_variables() !== [] || nibwp_bdpro_selectors() !== [];
}

/**
 * Find the variable that matches a literal value, if one does.
 *
 * Colors are compared case-insensitively and with three-digit hex expanded, so
 * `#FFF` matches a token stored as `#ffffff`.
 */
function nibwp_bdpro_match_token(string $literal): ?array
{
    $normalise = static function (string $value): string {
        $value = strtolower(trim($value));
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $m)) {
            return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }

        return $value;
    };

    $target = $normalise($literal);

    foreach (nibwp_bdpro_variables() as $variable) {
        if ($normalise($variable['value']) === $target) {
            return $variable;
        }
    }

    return null;
}

/**
 * Every literal color and font-size in a payload that a token could replace.
 *
 * Reported rather than rewritten. Swapping a color for a token that merely
 * happens to hold the same value today is how a section quietly changes
 * appearance the next time someone edits the palette — the agent should be told
 * and decide, not surprised.
 *
 * @return list<array{path:string, value:string, token:string}>
 */
function nibwp_bdpro_tokenisable(array $properties, string $prefix = ''): array
{
    $found = [];

    foreach ($properties as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

        if (is_array($value)) {
            $found = array_merge($found, nibwp_bdpro_tokenisable($value, $path));
            continue;
        }

        if (!is_string($value)) {
            continue;
        }

        $is_color = (bool) preg_match('/^#[0-9a-fA-F]{3,8}$|^rgba?\(/', trim($value));
        $is_size = (bool) preg_match('/^\d+(\.\d+)?(px|rem|em)$/', trim($value));

        if (!$is_color && !$is_size) {
            continue;
        }

        $token = nibwp_bdpro_match_token($value);
        if ($token !== null) {
            $found[] = ['path' => $path, 'value' => $value, 'token' => $token['name']];
        }
    }

    return $found;
}
