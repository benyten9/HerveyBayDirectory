<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/../lib/delegate.php';
require_once __DIR__ . '/../lib/validator.php';

/**
 * Mosaic Pro — refine. Change named elements of an existing page without
 * rebuilding it: new words, a style for one breakpoint, a class, a link, or
 * removal. Each change finds its element by id or by a match (name, words,
 * type, tag) that must hit exactly one element, so "the heading" can never
 * quietly mean the wrong one.
 */

nibwp_mosaic_pro_register(
    'mosaic-pro-refine',
    __('Mosaic Pro — Refine elements', 'nibwp'),
    'Change elements of a Mosaic page/template/component in place: words, per-breakpoint style, css, classes, tag, url, alt, image, or remove. Each change targets node_id or a match {name, text, type, tag} that must hit exactly one element. dry_run defaults to true.',
    [
        'post_id'       => ['type' => 'integer'],
        'document_type' => ['type' => 'string', 'enum' => ['template', 'master', 'component']],
        'document_id'   => ['type' => 'string'],
        'theme_id'      => ['type' => 'string'],
        'changes'       => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => '[{node_id | match:{name?,text?,type?,tag?}, text?, style?, replace_style?, css?, classes?, tag?, url?, target?, alt?, image?, data?, remove?}]'],
        'dry_run'       => ['type' => 'boolean'],
    ],
    'nibwp_mosaic_pro_refine_ability',
    false,
    [
        'Read the page first (nibwp/mosaic-pro-inspect) so each change names the right element.',
        'A match that hits several elements is refused with the candidates listed; narrow it or use node_id.',
        'Style merges per breakpoint: {mobile: {font-size: "32px"}} changes only the phone size.',
    ],
    ['changes']
);

function nibwp_mosaic_pro_refine_ability(array $input)
{
    $dry_run = !array_key_exists('dry_run', $input) || !empty($input['dry_run']);
    $gate = nibwp_mosaic_pro_gate(!$dry_run);
    if ($gate instanceof WP_Error) {
        return $gate;
    }

    $where = array_filter([
        'post_id'       => (int) ($input['post_id'] ?? 0),
        'document_type' => (string) ($input['document_type'] ?? ''),
        'document_id'   => (string) ($input['document_id'] ?? ''),
        'theme_id'      => (string) ($input['theme_id'] ?? ''),
    ], static fn($v) => $v !== '' && $v !== 0);
    $read = nibwp_mosaic_pro_delegate('mosaic-get-tree', $where);
    if ($read instanceof WP_Error) {
        return $read;
    }
    $tree = (array) ($read['tree'] ?? []);
    $context = nibwp_mosaic_pro_context($input);

    $plan = [];
    $errors = [];
    foreach (array_values((array) ($input['changes'] ?? [])) as $i => $change) {
        if (!is_array($change)) {
            continue;
        }
        $node = nibwp_mosaic_pro_resolve($tree, $change, $errors, $i);
        if ($node === null) {
            continue;
        }
        if (!empty($change['remove'])) {
            $plan[] = ['op' => 'delete', 'node' => $node, 'input' => ['node_id' => $node['id']]];
            continue;
        }
        $patch = array_intersect_key($change, array_flip(['text', 'style', 'replace_style', 'css', 'classes', 'cssClasses', 'tag', 'name', 'url', 'target', 'alt', 'image', 'data']));
        if ($patch === []) {
            $errors[] = ['change' => $i, 'message' => 'Nothing to change: give text, style, css, classes, tag, url, alt, image, data or remove.'];
            continue;
        }
        // Validate the change as a node of the element's own type.
        $probe = array_diff_key($patch, ['text' => 1, 'replace_style' => 1]) + ['type' => $node['type']];
        $check = nibwp_mosaic_pro_validate([$probe], $context);
        foreach ($check['errors'] as $e) {
            if (!in_array($e['rule'], ['empty_text', 'image_source'], true)) {
                $errors[] = ['change' => $i, 'node' => $node['id'], 'message' => $e['message']];
            }
        }
        $plan[] = ['op' => 'update', 'node' => $node, 'input' => ['node_id' => $node['id']] + $patch];
    }
    if ($errors !== []) {
        return new WP_Error('nibwp_mosaic_pro_refine_invalid', __('Some changes cannot be made; nothing was written.', 'nibwp'), ['errors' => $errors]);
    }

    $results = [];
    foreach ($plan as $step) {
        $label = $step['node']['type'] . '#' . $step['node']['id'];
        $result = nibwp_mosaic_pro_delegate($step['op'] === 'delete' ? 'mosaic-delete-node' : 'mosaic-update-node', $step['input'] + ['dry_run' => $dry_run]);
        if ($result instanceof WP_Error) {
            $results[] = ['element' => $label, 'op' => $step['op'], 'error' => $result->get_error_message()];
            break;
        }
        $results[] = ['element' => $label, 'op' => $step['op'], 'dropped' => $result['dropped'] ?? [], 'text' => $result['text'] ?? null];
    }

    return [
        'dry_run' => $dry_run,
        'changes' => $results,
        'next'    => $dry_run ? 'Nothing was written. Call again with dry_run: false to apply.' : 'Applied. Ask anyone with this page open in the Mosaic editor to reload it.',
    ];
}

/** Find exactly one element for a change. */
function nibwp_mosaic_pro_resolve(array $tree, array $change, array &$errors, int $i): ?array
{
    $all = [];
    $walk = static function (array $nodes) use (&$walk, &$all): void {
        foreach ($nodes as $n) {
            if (!str_starts_with((string) ($n['type'] ?? ''), 'wysiwyg-')) {
                $all[] = $n;
            }
            $walk((array) ($n['children'] ?? []));
        }
    };
    $walk($tree);

    if (!empty($change['node_id'])) {
        foreach ($all as $n) {
            if ($n['id'] === $change['node_id']) {
                return $n;
            }
        }
        $errors[] = ['change' => $i, 'message' => 'node_id ' . $change['node_id'] . ' is not in this document.'];

        return null;
    }

    $match = (array) ($change['match'] ?? []);
    if ($match === []) {
        $errors[] = ['change' => $i, 'message' => 'Give node_id or match.'];

        return null;
    }
    $hits = array_values(array_filter($all, static function (array $n) use ($match): bool {
        if (isset($match['type']) && $n['type'] !== $match['type']) {
            return false;
        }
        if (isset($match['tag']) && strtolower((string) ($n['data']['tagName'] ?? '')) !== strtolower((string) $match['tag'])) {
            return false;
        }
        if (isset($match['name']) && strcasecmp((string) ($n['data']['name'] ?? ''), (string) $match['name']) !== 0) {
            return false;
        }
        if (isset($match['text']) && stripos(nibwp_mosaic_node_text($n), (string) $match['text']) === false) {
            return false;
        }

        return true;
    }));
    // A text inside a button both "contain" the words; prefer the innermost.
    if (count($hits) > 1 && isset($match['text']) && !isset($match['type'])) {
        $ids = array_column($hits, 'id');
        $hits = array_values(array_filter($hits, static function (array $n) use ($ids): bool {
            foreach ((array) ($n['children'] ?? []) as $c) {
                if (in_array($c['id'] ?? '', $ids, true)) {
                    return false;
                }
            }

            return true;
        }));
    }
    if (count($hits) === 1) {
        return $hits[0];
    }
    $errors[] = [
        'change'     => $i,
        'message'    => count($hits) === 0 ? 'No element matches.' : count($hits) . ' elements match; narrow the match or use node_id.',
        'candidates' => array_map(static fn($n) => ['id' => $n['id'], 'type' => $n['type'], 'name' => $n['data']['name'] ?? '', 'text' => substr(nibwp_mosaic_node_text($n), 0, 60)], array_slice($hits, 0, 10)),
    ];

    return null;
}
