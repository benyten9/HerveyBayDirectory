<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Bricks global classes — the one place that merges them into Bricks' option.
 *
 * Bricks stores `bricks_global_classes` as a list of {id, name, settings} and
 * reads `$global_class['id']` and `$global_class['name']` without checking they
 * exist — see its ajax.php, api.php and assets.php. An entry missing either key
 * therefore does not merely fail to apply: it raises undefined-index warnings
 * inside Bricks itself, on pages the customer is looking at.
 *
 * There were two copies of this merge. One filled the required keys in; the
 * other appended whatever the payload contained. Two implementations of the
 * same rule is how one of them ends up wrong, so there is now one.
 */

/**
 * Merge payload classes into Bricks' global classes option.
 *
 * Existing classes are matched by name and updated rather than duplicated, so
 * re-running a conversion refines a class instead of adding a second one.
 *
 * @param array<int, mixed> $incoming Classes from the agent payload.
 * @return array{added: array<int, string>, updated: array<int, string>, skipped: int}
 */
function nibwp_bricks_merge_global_classes(array $incoming): array
{
    $existing = get_option('bricks_global_classes', []);
    $existing = is_array($existing) ? $existing : [];

    $by_name = [];
    foreach ($existing as $cls) {
        if (is_array($cls) && !empty($cls['name'])) {
            $by_name[(string) $cls['name']] = $cls;
        }
    }

    $added   = [];
    $updated = [];
    $skipped = 0;

    foreach ($incoming as $gc) {
        // A non-array here would raise its own warning the moment we indexed it.
        if (!is_array($gc) || empty($gc['name']) || !is_string($gc['name'])) {
            $skipped++;
            continue;
        }

        $name  = (string) $gc['name'];
        $known = array_key_exists($name, $by_name);

        // id and settings are what Bricks reads unguarded, so they are filled in
        // here rather than trusted from the payload. An existing id is kept:
        // changing it would orphan every element already referencing the class.
        $merged = array_merge($by_name[$name] ?? [], $gc, [
            'id'       => $by_name[$name]['id'] ?? ($gc['id'] ?? bin2hex(random_bytes(3))),
            'name'     => $name,
            'settings' => is_array($gc['settings'] ?? null) ? $gc['settings'] : ($by_name[$name]['settings'] ?? []),
        ]);

        if ($known && $by_name[$name] === $merged) {
            continue; // Nothing changed — do not report a write that did not happen.
        }

        $by_name[$name] = $merged;
        if ($known) {
            $updated[] = $name;
        } else {
            $added[] = $name;
        }
    }

    if ($added !== [] || $updated !== []) {
        update_option('bricks_global_classes', array_values($by_name), false);
    }

    return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped];
}
