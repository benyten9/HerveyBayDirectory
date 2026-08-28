<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * ACSS Pro — config validator.
 *
 * Hard rules (validation.failed → reject):
 *   - contrast_fail            : heading/body on background < WCAG AA
 *   - scale_ratio_insane       : type ratio outside [1.067, 1.618]
 *   - space_scale_drift        : space ramp not a consistent ratio
 *   - palette_too_close        : primary/secondary delta-E < 30
 *   - neutral_ramp_gap         : neutral ramp light/dark luminance < 80%
 *   - viewport_minmax_inverted : font-size-min >= font-size-max
 *   - brand_dark_undertinted   : --primary-dark luminance Δ vs --primary < 20%
 *
 * Warnings (validation.warnings → surface but accept):
 *   - few_brand_colors         : palette has < 2 colors
 *   - missing_radius           : no radius scale defined
 */

/**
 * Validate an ACSS-style config payload.
 *
 * @param array<string,mixed> $config
 * @param array<string,mixed> $ctx
 * @return array{passed:bool,failed:array<int,array{id:string,msg:string,path:string,fix_hint:string}>,warnings:array<int,array{id:string,msg:string,path:string}>}
 */
function nibwp_acss_validate_config(array $config, array $ctx = []): array
{
    $failed   = [];
    $warnings = [];

    $colors = (array) ($config['colors'] ?? []);
    $type   = (array) ($config['type']   ?? []);
    $space  = (array) ($config['space']  ?? []);
    $radius = (array) ($config['radius'] ?? []);

    // Palette checks.
    $primary   = (string) ($colors['primary']   ?? '');
    $secondary = (string) ($colors['secondary'] ?? '');
    $primary_dark = (string) ($colors['primary_dark'] ?? '');
    $bg = (string) ($colors['background'] ?? '#ffffff');
    $heading_color = (string) ($colors['heading'] ?? $primary);
    $body_color    = (string) ($colors['body'] ?? $colors['text'] ?? '#1a1a1a');

    if ($primary !== '' && $secondary !== '' && nibwp_acss_delta_e($primary, $secondary) < 30) {
        $failed[] = [
            'id'   => 'palette_too_close',
            'msg'  => sprintf('Primary %s and secondary %s have delta-E %.1f < 30 — visually indistinguishable.', $primary, $secondary, nibwp_acss_delta_e($primary, $secondary)),
            'path' => 'colors',
        ];
    }
    if ($primary !== '' && $primary_dark !== '') {
        $lum_a = nibwp_acss_relative_luminance($primary);
        $lum_b = nibwp_acss_relative_luminance($primary_dark);
        if ($lum_a > 0 && abs($lum_a - $lum_b) / max($lum_a, 0.0001) < 0.20) {
            $failed[] = [
                'id'   => 'brand_dark_undertinted',
                'msg'  => sprintf('--primary-dark luminance Δ vs --primary is %.1f%% (< 20%%). Increase darkness.', abs($lum_a - $lum_b) / max($lum_a, 0.0001) * 100),
                'path' => 'colors.primary_dark',
            ];
        }
    }

    // Heading + body contrast against background.
    $ratio_heading = nibwp_acss_contrast_ratio($heading_color, $bg);
    if ($ratio_heading < 3.0) {
        $failed[] = [
            'id'   => 'contrast_fail',
            'msg'  => sprintf('Heading on background contrast ratio %.2f < 3.0 (WCAG AA large text).', $ratio_heading),
            'path' => 'colors.heading',
        ];
    }
    $ratio_body = nibwp_acss_contrast_ratio($body_color, $bg);
    if ($ratio_body < 4.5) {
        $failed[] = [
            'id'   => 'contrast_fail',
            'msg'  => sprintf('Body on background contrast ratio %.2f < 4.5 (WCAG AA body text).', $ratio_body),
            'path' => 'colors.body',
        ];
    }

    // Neutral ramp light/dark luminance gap.
    $neutral_light = (string) ($colors['neutral_light'] ?? '');
    $neutral_dark  = (string) ($colors['neutral_dark']  ?? '');
    if ($neutral_light !== '' && $neutral_dark !== '') {
        $lum_l = nibwp_acss_relative_luminance($neutral_light);
        $lum_d = nibwp_acss_relative_luminance($neutral_dark);
        if (abs($lum_l - $lum_d) < 0.80) {
            $failed[] = [
                'id'   => 'neutral_ramp_gap',
                'msg'  => sprintf('Neutral light (%s) → dark (%s) luminance gap %.2f (< 0.80). Insufficient depth.', $neutral_light, $neutral_dark, abs($lum_l - $lum_d)),
                'path' => 'colors.neutral',
            ];
        }
    }

    // Type scale ratio sanity.
    $type_ratio = (float) ($type['scale_ratio'] ?? 0);
    if ($type_ratio > 0 && ($type_ratio < 1.067 || $type_ratio > 1.618)) {
        $failed[] = [
            'id'   => 'scale_ratio_insane',
            'msg'  => sprintf('Type scale ratio %.3f outside [1.067, 1.618]. Use a canonical modular scale: minor-second (1.067), minor-third (1.200), perfect-fourth (1.333), golden (1.618).', $type_ratio),
            'path' => 'type.scale_ratio',
        ];
    }
    $size_min = (float) ($type['size_min'] ?? 0);
    $size_max = (float) ($type['size_max'] ?? 0);
    if ($size_min > 0 && $size_max > 0 && $size_min >= $size_max) {
        $failed[] = [
            'id'   => 'viewport_minmax_inverted',
            'msg'  => sprintf('font-size min (%s) >= max (%s).', $size_min, $size_max),
            'path' => 'type',
        ];
    }

    // Space ramp consistency (ratio between consecutive steps).
    if (!empty($space['scale']) && is_array($space['scale']) && count($space['scale']) >= 3) {
        $values = array_values(array_map('floatval', $space['scale']));
        $ratios = [];
        for ($i = 1, $n = count($values); $i < $n; $i++) {
            if ($values[$i - 1] <= 0) {
                continue;
            }
            $ratios[] = $values[$i] / $values[$i - 1];
        }
        if ($ratios !== []) {
            $mean = array_sum($ratios) / count($ratios);
            $max_dev = 0.0;
            foreach ($ratios as $r) {
                $max_dev = max($max_dev, abs($r - $mean));
            }
            if ($mean > 0 && ($max_dev / $mean) > 0.25) {
                $failed[] = [
                    'id'   => 'space_scale_drift',
                    'msg'  => sprintf('Space scale ratios drift > 25%% from mean %.3f (max deviation %.3f). Use a single modular ratio.', $mean, $max_dev),
                    'path' => 'space.scale',
                ];
            }
        }
    }

    // Warnings.
    if (count(array_filter([$primary, $secondary])) < 2) {
        $warnings[] = ['id' => 'few_brand_colors', 'msg' => 'Palette has fewer than 2 brand colors (primary + secondary recommended).', 'path' => 'colors'];
    }
    if (empty($radius)) {
        $warnings[] = ['id' => 'missing_radius', 'msg' => 'No radius scale defined. Even a single --radius value avoids square corners.', 'path' => 'radius'];
    }

    // Decorate every failed entry with fix_hint.
    $failed = array_map(static function (array $item): array {
        if (!array_key_exists('fix_hint', $item) || $item['fix_hint'] === '') {
            $item['fix_hint'] = nibwp_acss_fix_hint_for($item);
        }
        return $item;
    }, $failed);

    return [
        'passed'   => $failed === [],
        'failed'   => array_values($failed),
        'warnings' => array_values($warnings),
    ];
}

/**
 * fix_hint dispatcher — pure function of $item['id'].
 */
function nibwp_acss_fix_hint_for(array $item): string
{
    switch ((string) ($item['id'] ?? '')) {
        case 'palette_too_close':
            return 'Shift the secondary color hue by ≥ 60° on the color wheel OR change its luminance ≥ 30%. delta-E ≥ 30 is the threshold for visual distinctness.';
        case 'brand_dark_undertinted':
            return 'Reduce primary_dark lightness by ≥ 20%. Example: `--primary: #2271b1` → `--primary-dark: #155087` (luminance drop ~30%).';
        case 'contrast_fail':
            return 'Increase contrast: darken text OR lighten background until ratio ≥ 4.5 (body) / 3.0 (large heading). Use a contrast checker like WebAIM.';
        case 'neutral_ramp_gap':
            return 'Use a deeper dark or lighter light: neutral_light should be near-white (≥ 0.92 luminance), neutral_dark near-black (≤ 0.10 luminance).';
        case 'scale_ratio_insane':
            return 'Pick a canonical modular scale: 1.067 (minor 2nd), 1.125 (major 2nd), 1.200 (minor 3rd), 1.250 (major 3rd), 1.333 (perfect 4th), 1.414 (aug 4th), 1.500 (perfect 5th), 1.618 (golden).';
        case 'viewport_minmax_inverted':
            return 'Ensure font-size min < max. Common: min=1rem, max=1.125rem for body; min=2rem, max=3.5rem for h1.';
        case 'space_scale_drift':
            return 'Use one ratio for the whole ramp. Example with 1.5×: 0.25/0.5/0.75/1/1.5/2.25/3.375. Or 1.618×: 0.382/0.618/1/1.618/2.618.';
        default:
            return 'See msg for details.';
    }
}

/**
 * Convert a hex/rgb color string to [r, g, b] in 0–1 range.
 */
function nibwp_acss_color_to_rgb(string $color): ?array
{
    $c = strtolower(trim($color));
    if ($c === '') {
        return null;
    }
    if (preg_match('/^#([0-9a-f]{3})$/i', $c, $m)) {
        $c = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
    }
    if (preg_match('/^#([0-9a-f]{6})(?:[0-9a-f]{2})?$/i', $c, $m)) {
        $hex = $m[1];
        return [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
    }
    if (preg_match('/^rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)/', $c, $m)) {
        return [
            ((float) $m[1]) / 255,
            ((float) $m[2]) / 255,
            ((float) $m[3]) / 255,
        ];
    }
    return null;
}

/**
 * WCAG relative luminance of an RGB color string. Returns 0–1.
 */
function nibwp_acss_relative_luminance(string $color): float
{
    $rgb = nibwp_acss_color_to_rgb($color);
    if ($rgb === null) {
        return 0.0;
    }
    $lin = array_map(static fn ($v) => $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4), $rgb);
    return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
}

/**
 * WCAG contrast ratio between two colors. Returns 1.0 on invalid input.
 */
function nibwp_acss_contrast_ratio(string $a, string $b): float
{
    $la = nibwp_acss_relative_luminance($a);
    $lb = nibwp_acss_relative_luminance($b);
    $hi = max($la, $lb);
    $lo = min($la, $lb);
    return ($hi + 0.05) / ($lo + 0.05);
}

/**
 * Approximate CIE76 delta-E between two RGB colors. Quick estimate —
 * sufficient for "are these visually distinguishable?" gating.
 */
function nibwp_acss_delta_e(string $a, string $b): float
{
    $ra = nibwp_acss_color_to_rgb($a);
    $rb = nibwp_acss_color_to_rgb($b);
    if ($ra === null || $rb === null) {
        return 0.0;
    }
    // RGB → Lab approximation via sRGB (skipping full XYZ for speed).
    $f = static function (array $rgb): array {
        $lin = array_map(static fn ($v) => $v <= 0.04045 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4), $rgb);
        // sRGB → XYZ (D65)
        $x = $lin[0] * 0.4124564 + $lin[1] * 0.3575761 + $lin[2] * 0.1804375;
        $y = $lin[0] * 0.2126729 + $lin[1] * 0.7151522 + $lin[2] * 0.0721750;
        $z = $lin[0] * 0.0193339 + $lin[1] * 0.1191920 + $lin[2] * 0.9503041;
        // Normalise to D65 whitepoint.
        $xn = $x / 0.95047;
        $yn = $y / 1.00000;
        $zn = $z / 1.08883;
        $g  = static fn ($t) => $t > 0.008856 ? pow($t, 1 / 3) : (7.787 * $t) + 16 / 116;
        $fx = $g($xn);
        $fy = $g($yn);
        $fz = $g($zn);
        return [
            116 * $fy - 16,         // L
            500 * ($fx - $fy),      // a
            200 * ($fy - $fz),      // b
        ];
    };
    $la = $f($ra);
    $lb = $f($rb);
    return sqrt(pow($la[0] - $lb[0], 2) + pow($la[1] - $lb[1], 2) + pow($la[2] - $lb[2], 2));
}
