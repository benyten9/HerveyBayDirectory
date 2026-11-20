<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Findings, in one vocabulary.
 *
 * Shared by the save and audit abilities, which both merge the etchwp
 * validator's output with the portability validator's.
 */

/**
 * Present both validators' findings in one vocabulary.
 *
 * The etchwp validator reports a finding's name under `id`; the portability
 * validator uses `rule`. An agent reading a refusal should not have to know
 * which gate spoke, so every finding carries both keys.
 *
 * @param array<int, array<string, mixed>> $findings
 * @return array<int, array<string, mixed>>
 */
function nibwp_etchedy_normalize_findings(array $findings): array
{
    return array_map(static function ($finding): array {
        $finding = (array) $finding;
        $name = (string) ($finding['rule'] ?? $finding['id'] ?? '');

        return ['id' => $name, 'rule' => $name] + $finding;
    }, $findings);
}

/**
 * Make sure the etchwp validator and persister are loaded.
 *
 * The build rules and the page persister live in the etchwp-pro skill, which
 * these abilities lean on rather than copy: one copy of the rules is the only
 * way a component judged clean here is also judged clean when it is built.
 * A site that has this skill without that one gets a sentence saying what to
 * install instead of a fatal "call to undefined function" halfway through a
 * save.
 *
 * Each file is loaded only when the function it declares is missing. They
 * declare plain functions, so a second copy loaded from another path would be
 * a compile error rather than a warning.
 *
 * Where it looks, when not told: beside this skill first, which is how Pro
 * ships them; then wherever the skills registry found the etchwp-pro skill,
 * which covers it being installed as its own plugin. The registry's
 * `requires_skills` check and this loader therefore agree about when the
 * dependency is met.
 *
 * @param string|null $lib_dir Directory holding validator.php and persister.php.
 *                             When given, it is the only place looked.
 */
function nibwp_etchedy_require_etchwp(?string $lib_dir = null): ?WP_Error
{
    $dirs = [];
    if ($lib_dir !== null) {
        $dirs[] = $lib_dir;
    } else {
        $dirs[] = dirname(__DIR__, 2) . '/etchwp-pro/lib/';
        if (function_exists('nibwp_skills_discover')) {
            $found = nibwp_skills_discover();
            if (isset($found['etchwp-pro']['path'])) {
                $dirs[] = rtrim((string) $found['etchwp-pro']['path'], '/\\') . '/lib/';
            }
        }
    }

    $needed = [
        'nibwp_etchwp_validate_payload' => 'validator.php',
        'nibwp_etchwp_persist_payload' => 'persister.php',
    ];

    foreach ($needed as $function => $file) {
        if (function_exists($function)) {
            continue;
        }
        foreach ($dirs as $dir) {
            if (!function_exists($function) && is_readable($dir . $file)) {
                require_once $dir . $file;
            }
        }
        if (!function_exists($function)) {
            return new WP_Error(
                'etchedy_requires_etchwp_pro',
                __('The Etchedy skill uses the build rules from the EtchWP Pro skill, and it is not available on this site. Install and activate EtchWP Pro, then try again.', 'nibwp'),
                ['status' => 424]
            );
        }
    }

    return null;
}
