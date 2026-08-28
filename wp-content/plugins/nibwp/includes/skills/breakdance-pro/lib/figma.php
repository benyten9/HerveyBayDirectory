<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Breakdance Pro — the Figma bridge.
 *
 * A Figma frame is a structure, not a picture. It carries a node tree,
 * auto-layout rules that say why things are spaced the way they are, and
 * Variables that name the colors and sizes rather than merely showing them.
 * Reading it as an image throws all three away and leaves the agent estimating
 * numbers it could have known exactly.
 *
 * So this file's job is to insist. It reports whether a real Figma connection
 * exists, judges whether a payload was actually read from the file or merely
 * looked at, and bridges Figma's tokens onto the site's own variables.
 */

/**
 * What can this site actually do with Figma right now?
 *
 * @return array{connected:bool, method:string, abilities:list<string>, can_read_structure:bool}
 */
function nibwp_bdpro_figma_status(): array
{
    $connected = function_exists('nibwp_figma_is_connected')
        ? nibwp_figma_is_connected()
        : (bool) get_option('nibwp_figma_token');

    $method = function_exists('nibwp_figma_connection_method')
        ? (string) nibwp_figma_connection_method()
        : ($connected ? 'token' : '');

    $abilities = [];
    if (function_exists('wp_get_abilities')) {
        foreach (wp_get_abilities() as $ability) {
            $name = is_object($ability) && method_exists($ability, 'get_name')
                ? $ability->get_name()
                : (string) $ability;

            if (str_starts_with($name, 'nibwp/figma')) {
                $abilities[] = $name;
            }
        }
    }

    sort($abilities);

    return [
        'connected'          => $connected,
        'method'             => $method,
        'abilities'          => $abilities,
        // Structure needs both a connection and something able to fetch nodes.
        // Either half alone reads a picture at best.
        'can_read_structure' => $connected && $abilities !== [],
    ];
}

/**
 * Was this payload read from the Figma file, or merely looked at?
 *
 * Judged on evidence rather than on the agent's word for it. A structural read
 * carries node IDs, frame geometry, auto-layout modes and named tokens; an
 * image-derived one carries none of those no matter how confidently it is
 * labelled.
 *
 * @return array{structural:bool, evidence:list<string>, missing:list<string>, score:int}
 */
function nibwp_bdpro_figma_assess(array $figma): array
{
    $evidence = [];
    $missing = [];

    $has = static function (array $figma, array $keys): bool {
        foreach ($keys as $key) {
            if (!empty($figma[$key])) {
                return true;
            }
        }

        return false;
    };

    if ($has($figma, ['nodes', 'node_tree', 'document'])) {
        $evidence[] = 'node tree';
    } else {
        $missing[] = 'nodes — the node tree read from the file';
    }

    if ($has($figma, ['frames', 'frame'])) {
        $evidence[] = 'frames';
    } else {
        $missing[] = 'frames — frame names and geometry';
    }

    if (nibwp_bdpro_figma_has_deep_key($figma, ['layoutMode', 'layout_mode', 'autoLayout', 'auto_layout'])) {
        $evidence[] = 'auto-layout';
    } else {
        $missing[] = 'layoutMode — auto-layout is what tells you why spacing is what it is';
    }

    if (nibwp_bdpro_figma_has_deep_key($figma, ['absoluteBoundingBox', 'boundingBox', 'size'])) {
        $evidence[] = 'geometry';
    } else {
        $missing[] = 'absoluteBoundingBox — real measurements rather than estimates';
    }

    if ($has($figma, ['tokens', 'variables', 'styles'])) {
        $evidence[] = 'variables';
    } else {
        $missing[] = 'variables — named tokens rather than raw color values';
    }

    if (!empty($figma['node_id']) || !empty($figma['file_key'])) {
        $evidence[] = 'file reference';
    }

    $score = count($evidence);

    return [
        // Three of the six signals is the line. Fewer than that and whatever
        // produced this could not have been reading the file.
        'structural' => $score >= 3,
        'evidence'   => $evidence,
        'missing'    => $missing,
        'score'      => $score,
    ];
}

/** Does any key appear anywhere in a nested structure? */
function nibwp_bdpro_figma_has_deep_key(array $data, array $keys, int $depth = 0): bool
{
    if ($depth > 8) {
        return false;
    }

    foreach ($data as $key => $value) {
        if (in_array((string) $key, $keys, strict: true)) {
            return true;
        }
        if (is_array($value) && nibwp_bdpro_figma_has_deep_key($value, $keys, $depth + 1)) {
            return true;
        }
        if (is_object($value) && nibwp_bdpro_figma_has_deep_key((array) $value, $keys, $depth + 1)) {
            return true;
        }
    }

    return false;
}

/**
 * Map Figma's tokens onto the variables this site already defines.
 *
 * Matching is by value, because the names almost never agree — a Figma
 * Variable called `Brand/Primary` and a Breakdance variable called
 * `brand-primary` are the same color under two naming conventions, and only
 * the value says so.
 *
 * Unmatched tokens are reported rather than created. Adding variables to a
 * site's design system is a decision its owner makes.
 *
 * @return array{matched:list<array>, unmatched:list<array>, coverage:int}
 */
function nibwp_bdpro_figma_token_bridge(array $figma): array
{
    $figma_tokens = [];

    foreach (['tokens', 'variables', 'styles'] as $key) {
        foreach ((array) ($figma[$key] ?? []) as $name => $value) {
            if (is_array($value) || is_object($value)) {
                $value = (array) $value;
                $figma_tokens[] = [
                    'name'  => (string) ($value['name'] ?? $name),
                    'value' => (string) ($value['value'] ?? ($value['hex'] ?? '')),
                ];
                continue;
            }

            $figma_tokens[] = ['name' => (string) $name, 'value' => (string) $value];
        }
    }

    $matched = [];
    $unmatched = [];

    foreach ($figma_tokens as $token) {
        if ($token['value'] === '') {
            continue;
        }

        $site = nibwp_bdpro_match_token($token['value']);

        if ($site !== null) {
            $matched[] = [
                'figma'      => $token['name'],
                'breakdance' => $site['name'],
                'value'      => $token['value'],
            ];
        } else {
            $unmatched[] = $token;
        }
    }

    $total = count($matched) + count($unmatched);

    return [
        'matched'   => $matched,
        'unmatched' => $unmatched,
        'coverage'  => $total === 0 ? 0 : (int) round((count($matched) / $total) * 100),
    ];
}

/**
 * The guidance to hand back when the Figma path cannot run properly.
 *
 * @return list<string>
 */
function nibwp_bdpro_figma_next_steps(array $status): array
{
    if (!$status['connected']) {
        return [
            __('Connect a Figma account under NIBWP → Integrations → Figma.', 'nibwp'),
            __('Then read the frame with nibwp/figma-pro-fetch, which returns the node tree, auto-layout and Variables.', 'nibwp'),
            __('Without a connection the frame can only be looked at as a picture, and every size and color becomes an estimate.', 'nibwp'),
        ];
    }

    if ($status['abilities'] === []) {
        return [
            __('A Figma account is connected but no Figma abilities are registered — check the Figma integration is switched on.', 'nibwp'),
        ];
    }

    return [
        __('Read the frame with nibwp/figma-pro-fetch and pass what it returns as the figma argument.', 'nibwp'),
        __('nibwp/figma-pro-analyze first if the frame is large — it reports the structure before you convert it.', 'nibwp'),
    ];
}
