<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/converter.php';

/**
 * Elementor Pro skill — scorer.
 *
 * Four honest sub-scores derived from the real tree + validator findings (never
 * a flattering constant):
 *   structure  — valid hierarchy, no dup/missing ids, containers over legacy
 *   native     — real widgets over html widgets; no unknown control ids
 *   responsive — breakpoint values present
 *   visual     — styling carried in settings (controls), alt text on images
 *
 * @return array{score:int,grade:string,structure:int,native:int,responsive:int,visual:int}
 */
function nibwp_elementor_pro_score(array $tree, array $validation): array
{
    $flat    = nibwp_elementor_pro_flatten($tree);
    $total   = max(1, count($flat));
    $widgets = array_values(array_filter($flat, static fn ($e) => ($e['elType'] ?? '') === 'widget'));
    $wcount  = max(1, count($widgets));

    $warnings = (array) ($validation['warnings'] ?? []);
    $failed   = (array) ($validation['failed'] ?? []);

    $count_warn = static function (string $needle) use ($warnings): int {
        $n = 0;
        foreach ($warnings as $w) {
            if (stripos((string) $w, $needle) !== false) {
                $n++;
            }
        }
        return $n;
    };

    // ── structure ──
    $structure = 100;
    $structure -= count($failed) * 20;                       // any hard fail is severe
    $structure -= $count_warn('Legacy') * 6;                 // section/column usage
    $structure = max(0, min(100, $structure));

    // ── native ──
    $html_widgets = count(array_filter($widgets, static fn ($w) => ($w['widgetType'] ?? '') === 'html'));
    $unknown_ctrl = $count_warn('not a known control id');
    $native = 100 - (int) round(($html_widgets / $wcount) * 60) - min(30, $unknown_ctrl * 3);
    $native = max(0, min(100, $native));

    // ── responsive ──
    $responsive = $count_warn('desktop-only') > 0 ? 55 : 100;
    // scale slightly by how many widgets carry any breakpoint key
    $with_bp = 0;
    foreach ($widgets as $w) {
        foreach (array_keys((array) ($w['settings'] ?? [])) as $k) {
            if (preg_match('/_(tablet|mobile|widescreen|laptop|tablet_extra|mobile_extra)$/', (string) $k)) {
                $with_bp++;
                break;
            }
        }
    }
    if ($responsive === 100) {
        $responsive = 70 + (int) round(($with_bp / $wcount) * 30);
    }
    $responsive = max(0, min(100, $responsive));

    // ── visual ──
    $styled = 0;
    foreach ($flat as $el) {
        if (!empty($el['settings'])) {
            $styled++;
        }
    }
    $visual = (int) round(($styled / $total) * 100);
    $visual -= $count_warn('no attachment id') * 5;
    $visual = max(0, min(100, $visual));

    $score = (int) round($structure * 0.35 + $native * 0.30 + $responsive * 0.15 + $visual * 0.20);

    return [
        'score'      => $score,
        'grade'      => nibwp_elementor_pro_grade($score),
        'structure'  => $structure,
        'native'     => $native,
        'responsive' => $responsive,
        'visual'     => $visual,
    ];
}

function nibwp_elementor_pro_grade(int $s): string
{
    return match (true) {
        $s >= 92 => 'A',
        $s >= 83 => 'B',
        $s >= 72 => 'C',
        $s >= 60 => 'D',
        default  => 'F',
    };
}
