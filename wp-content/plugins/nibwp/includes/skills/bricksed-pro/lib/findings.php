<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Findings, in one vocabulary, and the build rules they come from.
 *
 * Shared by the save and audit abilities, which both merge the bricks-pro
 * validator's output with the portability validator's.
 */

/**
 * Present both validators' findings in one vocabulary.
 *
 * The bricks-pro validator reports a finding's name under `id`; the
 * portability validator uses `rule`. Every finding carries both keys.
 *
 * @param array<int, array<string, mixed>> $findings
 * @return array<int, array<string, mixed>>
 */
function nibwp_bricksed_normalize_findings(array $findings): array
{
    return array_map(static function ($finding): array {
        $finding = (array) $finding;
        $name = (string) ($finding['rule'] ?? $finding['id'] ?? '');

        return ['id' => $name, 'rule' => $name] + $finding;
    }, $findings);
}

/**
 * Make sure the bricks-pro validator is loaded.
 *
 * The build rules live in the Bricks Pro skill, which these abilities lean on
 * rather than copy: one copy of the rules is the only way a component judged
 * clean here is also judged clean when it is built. A site that has this skill
 * without that one gets a sentence saying what to install instead of a fatal
 * "call to undefined function" halfway through a save.
 *
 * Where it looks, when not told: beside this skill first, which is how Pro
 * ships them; then wherever the skills registry found the bricks-pro skill.
 *
 * @param string|null $lib_dir Directory holding validator.php. When given, it is the only place looked.
 */
function nibwp_bricksed_require_bricks_pro(?string $lib_dir = null): ?WP_Error
{
    if (function_exists('nibwp_bricks_pro_validate_payload')) {
        return null;
    }

    $dirs = [];
    if ($lib_dir !== null) {
        $dirs[] = $lib_dir;
    } else {
        $dirs[] = dirname(__DIR__, 2) . '/bricks-pro/lib/';
        if (function_exists('nibwp_skills_discover')) {
            $found = nibwp_skills_discover();
            if (isset($found['bricks-pro']['path'])) {
                $dirs[] = rtrim((string) $found['bricks-pro']['path'], '/\\') . '/lib/';
            }
        }
    }

    foreach ($dirs as $dir) {
        if (!function_exists('nibwp_bricks_pro_validate_payload') && is_readable($dir . 'validator.php')) {
            require_once $dir . 'validator.php';
        }
    }

    if (!function_exists('nibwp_bricks_pro_validate_payload')) {
        return new WP_Error(
            'bricksed_requires_bricks_pro',
            __('The Bricksed skill uses the build rules from the Bricks Pro skill, and it is not available on this site. Install and activate Bricks Pro, then try again.', 'nibwp'),
            ['status' => 424]
        );
    }

    return null;
}

/**
 * Run the bricks-pro build rules over Bricks data.
 *
 * The validator reads { elements, global_classes }. A component's own
 * elements are part of what gets built, so they are checked as well, under
 * their own paths.
 *
 * @param array<string, mixed> $bricks From nibwp_bricksed_bricks_of().
 * @return array{failed: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>}
 */
function nibwp_bricksed_quality(array $bricks, string $brand): array
{
    $elements = array_values((array) $bricks['content']);
    foreach ((array) ($bricks['components'] ?? []) as $component) {
        foreach ((array) ($component['elements'] ?? []) as $element) {
            $elements[] = $element;
        }
    }

    $result = nibwp_bricks_pro_validate_payload([
        'elements' => $elements,
        'global_classes' => array_values((array) $bricks['globalClasses']),
    ], ['brand' => $brand]);

    return ['failed' => (array) $result['failed'], 'warnings' => (array) $result['warnings']];
}
