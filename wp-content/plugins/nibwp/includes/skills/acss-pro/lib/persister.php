<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * ACSS Pro — config persister.
 *
 * Writes the validated config into the ACSS plugin's settings option.
 * Defense in depth: re-validates the config on entry before writing.
 *
 * ACSS's own settings model is the write path; the option is only touched when
 * that model is absent. Which option that is varies by ACSS version, so it is
 * resolved by nibwp_acss_settings_option_name() rather than hardcoded here.
 */

require_once __DIR__ . '/validator.php';

/**
 * Persist an ACSS config payload. Returns the diff applied to the option.
 *
 * @param array<string,mixed> $config
 * @param array<string,mixed> $ctx { mode?, target_settings_group?: 'palette'|'typography'|'space'|'radius'|'all' }
 * @return array{settings_option:string,added:array<int,string>,updated:array<int,string>,unchanged:array<int,string>}|WP_Error
 */
function nibwp_acss_persist_config(array $config, array $ctx = [])
{
    $verdict = nibwp_acss_validate_config($config, $ctx);
    if (!$verdict['passed']) {
        return new WP_Error(
            'acss_persist_validation_failed',
            'Validator rejected ACSS config at persist gate.',
            ['failed' => $verdict['failed'], 'warnings' => $verdict['warnings']]
        );
    }

    $mode  = (string) ($ctx['mode'] ?? 'merge_only_new_keys');
    $group = (string) ($ctx['target_settings_group'] ?? 'all');

    // Canonical ACSS write path — DO NOT guess. Use Automatic.css's own settings
    // model so the config is saved in ACSS's exact schema AND the compiled CSS is
    // regenerated immediately:
    //   Automatic_CSS\Model\Database_Settings::get_instance()->save_settings($values, true)
    // The 2nd arg (trigger_css_generation) = true recompiles the dynamic CSS on the
    // spot. Read current values with ->get_vars(). Fall back to a raw option write
    // ONLY when the ACSS plugin isn't present (class missing).
    $db_class = 'Automatic_CSS\\Model\\Database_Settings';
    $use_api  = class_exists($db_class) && method_exists($db_class, 'get_instance');
    $db       = $use_api ? $db_class::get_instance() : null;
    $option_name = nibwp_acss_settings_option_name();

    $existing = $use_api ? (array) $db->get_vars() : (array) get_option($option_name, []);

    // Flatten AFTER reading ACSS's live schema so we emit ACSS's real var_ids
    // (4.x is OKLCH-based, e.g. primary-l/c/h-oklch — not hex color-primary).
    $flat = nibwp_acss_flatten_config($config, $group, $existing);

    $added = $updated = $unchanged = $unknown_keys = [];
    $next  = $existing;
    foreach ($flat as $k => $v) {
        if (!array_key_exists($k, $existing)) {
            // Key isn't in ACSS's live var schema — surface it instead of writing a
            // stray key the framework will never read.
            $unknown_keys[] = $k;
            if ($mode !== 'preserve_keep_existing') {
                $next[$k] = $v;
                $added[] = $k;
            }
        } elseif ($mode === 'overwrite_with_extracted') {
            if ($existing[$k] !== $v) {
                $next[$k] = $v;
                $updated[] = $k;
            } else {
                $unchanged[] = $k;
            }
        } else {
            $unchanged[] = $k;
        }
    }

    $regenerated = false;
    if ($added !== [] || $updated !== []) {
        update_option('nibwp_acss_settings_bak', $existing, false); // reversible
        if ($use_api) {
            // Saves ACSS's option in its own schema + recompiles the dynamic CSS now.
            $db->save_settings($next, true);
            $regenerated = true;
        } else {
            update_option($option_name, $next, false);
        }
    }

    return [
        'settings_option' => $use_api ? $db_class . '::save_settings' : $option_name,
        'via_acss_api'    => $use_api,
        'css_regenerated' => $regenerated,
        'added'           => $added,
        'updated'         => $updated,
        'unchanged'       => $unchanged,
        'unknown_keys'    => $unknown_keys, // flat keys not in ACSS's live var schema — review the flatten map if non-empty
    ];
}

/**
 * Flatten a tree-shaped config into the dotted-key shape ACSS stores in
 * its option. Conservative defaults so the agent can pass nested input
 * and the persister maps it onto ACSS's flat schema.
 *
 * @param string $group One of 'palette'|'typography'|'space'|'radius'|'all'.
 *                       When not 'all', only that group's keys are emitted —
 *                       the persister becomes a per-group write so a user who
 *                       chose target_settings_group=typography in preflight
 *                       gets ONLY the type ramp updated.
 * @return array<string,mixed>
 */
function nibwp_acss_flatten_config(array $config, string $group = 'all', array $schema = []): array
{
    // ACSS 4.x stores colors as OKLCH triples ({name}-l/c/h-oklch) and base
    // tokens as base-*. Detect that schema by a signature key and emit ACSS's
    // REAL var_ids so save_settings() actually applies (no unknown_keys no-op).
    if (array_key_exists('primary-l-oklch', $schema)) {
        return nibwp_acss_flatten_config_oklch($config, $group, $schema);
    }

    // Legacy path (pre-4.x / unknown schema).
    $out = [];
    $all = ($group === 'all');

    if ($all || $group === 'palette') {
        $colors = (array) ($config['colors'] ?? []);
        foreach ($colors as $k => $v) {
            if (is_string($v) && $v !== '') {
                $out['color-' . sanitize_key($k)] = $v;
            }
        }
    }
    if ($all || $group === 'typography') {
        $type = (array) ($config['type'] ?? []);
        foreach (['family_heading', 'family_body', 'scale_ratio', 'size_min', 'size_max'] as $k) {
            if (array_key_exists($k, $type)) {
                $out['type-' . $k] = $type[$k];
            }
        }
    }
    if ($all || $group === 'space') {
        $space = (array) ($config['space'] ?? []);
        if (!empty($space['scale']) && is_array($space['scale'])) {
            foreach ($space['scale'] as $i => $v) {
                $out['space-' . (int) $i] = $v;
            }
        }
        if (array_key_exists('scale_ratio', $space)) {
            $out['space-ratio'] = $space['scale_ratio'];
        }
    }
    if ($all || $group === 'radius') {
        $radius = (array) ($config['radius'] ?? []);
        foreach ($radius as $k => $v) {
            $out['radius-' . sanitize_key((string) $k)] = $v;
        }
    }
    if ($all) {
        // shadows + breakpoints aren't user-selectable preflight groups; only
        // included in the 'all' write.
        $shadows = (array) ($config['shadows'] ?? []);
        foreach ($shadows as $k => $v) {
            $out['shadow-' . sanitize_key((string) $k)] = $v;
        }
        $bps = (array) ($config['breakpoints'] ?? []);
        foreach ($bps as $k => $v) {
            $out['breakpoint-' . sanitize_key((string) $k)] = $v;
        }
    }
    return $out;
}

/**
 * ACSS 4.x flatten — emits ACSS's exact OKLCH + base-* input var_ids.
 *
 * Only writes keys that actually exist in the live $schema, so a token ACSS
 * doesn't expose is dropped (not sent as a stray key). Colors are converted
 * hex → OKLCH and split into the -l/-c/-h-oklch triple ACSS derives from.
 */
function nibwp_acss_flatten_config_oklch(array $config, string $group, array $schema): array
{
    $out = [];
    $all = ($group === 'all');
    $has = static fn(string $k): bool => array_key_exists($k, $schema);

    if ($all || $group === 'palette') {
        // our config color name → ACSS 4 color name
        $map = [
            'primary'   => 'primary',
            'secondary' => 'accent',
            'accent'    => 'accent',
            'action'    => 'action',
            'background' => 'base',
            'base'      => 'base',
            'shade'     => 'shade',
        ];
        foreach ((array) ($config['colors'] ?? []) as $ck => $hex) {
            $name = $map[$ck] ?? null;
            if ($name === null || !is_string($hex) || $hex === '') {
                continue;
            }
            $ok = nibwp_acss_hex_to_oklch($hex);
            if ($ok === null || !$has("$name-l-oklch")) {
                continue;
            }
            $out["$name-l-oklch"] = $ok['l'];
            $out["$name-c-oklch"] = $ok['c'];
            $out["$name-h-oklch"] = $ok['h'];
        }
    }
    if ($all || $group === 'typography') {
        $type = (array) ($config['type'] ?? []);
        // size_max = desktop body, size_min = mobile body (ACSS clamps between).
        if (isset($type['size_max']) && $has('base-text-desk')) {
            $out['base-text-desk'] = $type['size_max'];
        }
        if (isset($type['size_min']) && $has('base-text-mob')) {
            $out['base-text-mob'] = $type['size_min'];
        }
        // ponytail: font-family var_ids not yet verified on 4.x — skip rather
        // than guess a key. Add here once confirmed via get_vars().
    }
    if ($all || $group === 'space') {
        $space = (array) ($config['space'] ?? []);
        if (isset($space['base']) && $has('base-space')) {
            $out['base-space'] = $space['base'];
        }
    }
    if ($all || $group === 'radius') {
        $radius = (array) ($config['radius'] ?? []);
        if (isset($radius['base']) && $has('base-radius')) {
            $v = $radius['base'];
            $out['base-radius'] = is_numeric($v) ? ($v . 'px') : $v;
        }
    }
    return $out;
}

/**
 * sRGB hex → OKLCH. Returns ['l'=>0..1, 'c'=>chroma, 'h'=>hue°] or null on a
 * malformed hex. Standard Björn Ottosson OKLab transform.
 */
function nibwp_acss_hex_to_oklch(string $hex): ?array
{
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
        return null;
    }
    $srgb = [
        hexdec(substr($hex, 0, 2)) / 255,
        hexdec(substr($hex, 2, 2)) / 255,
        hexdec(substr($hex, 4, 2)) / 255,
    ];
    // sRGB → linear
    $lin = array_map(
        static fn(float $c): float => $c <= 0.04045 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4),
        $srgb
    );
    [$r, $g, $b] = $lin;
    // linear sRGB → LMS
    $l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
    $m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
    $s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;
    $l = $l ** (1 / 3);
    $m = $m ** (1 / 3);
    $s = $s ** (1 / 3);
    // LMS → OKLab
    $L  = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
    $a  = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
    $bb = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;
    $C = sqrt($a * $a + $bb * $bb);
    $H = rad2deg(atan2($bb, $a));
    if ($H < 0) {
        $H += 360;
    }
    return ['l' => round($L, 6), 'c' => round($C, 6), 'h' => round($H, 4)];
}
