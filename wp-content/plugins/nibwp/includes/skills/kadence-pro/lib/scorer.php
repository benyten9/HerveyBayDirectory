<?php

declare(strict_types=1);

/**
 * Kadence Pro — quality/fidelity scorer. Rewards native, attribute-driven output
 * and penalises the anti-patterns the KB bans (core/html, custom-class styling,
 * div sections, static loops). Not a gate — the validator is.
 */

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/registry.php';

/**
 * @param array<int,array<string,mixed>> $blocks
 * @param array{failed?:array<int,string>,warnings?:array<int,string>} $verdict
 * @return array{score:int, grade:string, notes:array<int,string>}
 */
function nibwp_kadence_score(array $blocks, array $verdict = []): array
{
    $notes = [];
    $score = 100;
    $score -= 25 * count((array) ($verdict['failed'] ?? []));
    $score -= 5 * count((array) ($verdict['warnings'] ?? []));

    $names = [];
    $div_sections = 0;
    $classname_ok = nibwp_kadence_classname_blocks();
    $bad_classname = 0;
    $walk = function ($nodes) use (&$walk, &$names, &$div_sections, &$bad_classname, $classname_ok): void {
        foreach ((array) $nodes as $n) {
            if (!is_array($n) || !isset($n['blockName'])) {
                continue;
            }
            $name = (string) $n['blockName'];
            $names[] = $name;
            $attrs = (array) ($n['attrs'] ?? []);
            if ($name === 'kadence/rowlayout' && ($attrs['htmlTag'] ?? 'div') === 'div') {
                $div_sections++;
            }
            if (!empty($attrs['className']) && !in_array($name, $classname_ok, true)) {
                $bad_classname++;
            }
            $walk($n['innerBlocks'] ?? []);
        }
    };
    $walk($blocks);
    $counts = array_count_values($names);

    if (($counts['core/html'] ?? 0) > 0) {
        $score -= 30;
        $notes[] = ($counts['core/html']) . ' core/html block(s) — banned; rebuild with native Kadence blocks.';
    }
    if (($counts['kadence/rowlayout'] ?? 0) === 0) {
        $score -= 10;
        $notes[] = 'No kadence/rowlayout — layout is not wrapped in Kadence sections.';
    }
    if ($div_sections > 0) {
        $score -= 4 * $div_sections;
        $notes[] = $div_sections . ' rowlayout(s) still htmlTag:"div" — set "section".';
    }
    if ($bad_classname > 0) {
        $score -= 4 * $bad_classname;
        $notes[] = $bad_classname . ' custom className(s) on non-className blocks — style via attributes, not CSS.';
    }
    if (($counts['kadence/advancedheading'] ?? 0) + ($counts['kadence/singlebtn'] ?? 0) + ($counts['kadence/infobox'] ?? 0) > 0) {
        $notes[] = 'Uses native Kadence content blocks (heading/button/infobox).';
    }

    $score = max(0, min(100, $score));
    $grade = $score >= 90 ? 'A' : ($score >= 75 ? 'B' : ($score >= 60 ? 'C' : ($score >= 40 ? 'D' : 'F')));
    return ['score' => $score, 'grade' => $grade, 'notes' => $notes];
}
