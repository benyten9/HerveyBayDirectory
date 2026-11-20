<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/../lib/delegate.php';
require_once __DIR__ . '/../lib/validate.php';
require_once __DIR__ . '/../lib/builder.php';

/**
 * Paper Pro — change one part, leave the rest alone.
 *
 * The reason this is not "convert it again with the new design" is that by the time
 * anyone asks, the page is not the conversion any more. Somebody fixed the heading,
 * somebody else swapped a photograph, an editor rewrote the second paragraph. A
 * rebuild throws all of that away and reports success, which is the worst
 * combination available.
 *
 * So refine reads the design the page was built from, replaces the named parts in it,
 * and converts THAT — one document, differing from the stored one only where the
 * caller said. A part that does not exist is refused with the parts that do, because
 * an edit applied to nothing and reported as done is how a client is told something
 * was changed that was not.
 */

wp_register_ability('nibwp/paper-pro-refine', [
    'label'       => __('Paper Pro — Refine a converted page', 'nibwp'),
    'description' => 'Change named parts of a page already converted from a Paper design — replace a subtree, restyle it, rewrite its text, or remove it — without rebuilding the rest. Reads the stored design, patches it, and converts through the Paper integration. Dry run by default.',
    'category'    => 'paper-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id'   => ['type' => 'integer', 'description' => 'The converted page to change.'],
            'design_id' => ['type' => 'integer', 'description' => 'The stored design the page was built from, if you know its id. Resolved from post_id otherwise.'],
            'edits' => [
                'type'        => 'array',
                'description' => 'Each edit names one part by its layer name or its NDO path and does one thing to it: {part, node?, style?, layout?, text?, remove?}. node replaces the subtree and is validated as NDO before anything is written; style and layout are merged into the part\'s own declarations, and a null value removes one.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'part'   => ['type' => 'string', 'description' => 'The layer name, or the NDO path as the plan and audit report it (root.children[2]).'],
                        'node'   => ['type' => 'object', 'description' => 'A replacement subtree in NDO node shape.'],
                        'style'  => ['type' => 'object', 'description' => 'CSS declarations merged into this part. null removes a declaration.'],
                        'layout' => ['type' => 'object', 'description' => 'Layout declarations merged into this part. null removes a declaration.'],
                        'text'   => ['type' => 'string', 'description' => 'New copy, for a text part.'],
                        'remove' => ['type' => 'boolean', 'description' => 'Remove this part and everything under it.'],
                    ],
                ],
            ],
            'builder' => ['type' => 'string', 'enum' => ['auto', 'etchwp', 'bricks', 'elementor', 'gutenberg']],
            'dry_run' => ['type' => 'boolean', 'default' => true, 'description' => 'True (the default) reports what would change and writes nothing.'],
        ],
        'required' => ['post_id', 'edits'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_paper_pro_refine_ability',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Read the page with nibwp/paper-pro-audit first — it reports the part names and paths this ability addresses.',
                'An edit that matches no part is refused, with the parts that exist. It is not applied to nothing and reported as done.',
                'A replacement node is validated as NDO before anything is written, by the same rules as a whole design.',
                'Removing a part removes everything under it. The response says how many nodes went.',
                'dry_run true first. The response lists the parts that would change.',
                'Do not reach for this to rebuild most of a page. Past a few parts, convert again from the design and say that is what you are doing.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_paper_pro_refine_ability(array $input)
{
    $dry_run = !array_key_exists('dry_run', $input) || (bool) $input['dry_run'];

    $gate = nibwp_paper_pro_gate(true, false);
    if ($gate instanceof WP_Error) {
        return $gate;
    }

    $post_id = (int) ($input['post_id'] ?? 0);
    $edits = array_values((array) ($input['edits'] ?? []));

    if ($post_id <= 0) {
        return new WP_Error(
            'nibwp_paper_pro_invalid_payload',
            __('Give the post_id of the converted page to refine.', 'nibwp')
        );
    }
    if ($edits === []) {
        return new WP_Error(
            'nibwp_paper_pro_invalid_payload',
            __('Give at least one edit. Refining nothing is a rebuild dressed up as a change.', 'nibwp')
        );
    }

    $stored = nibwp_paper_pro_delegate('get_design', [
        'post_id'   => $post_id,
        'design_id' => (int) ($input['design_id'] ?? 0),
    ]);
    if ($stored instanceof WP_Error) {
        return $stored;
    }

    $design = (array) ($stored['design'] ?? []);
    if ($design === [] || !isset($design['root'])) {
        return new WP_Error(
            'nibwp_paper_pro_no_stored_design',
            __('No stored design was found for that page, so there is nothing to patch and no way to tell which parts exist. Convert it from the design again, or hand the design over and use nibwp/paper-pro-convert with push_mode update.', 'nibwp')
        );
    }

    $patched = nibwp_paper_pro_apply_edits($design, $edits);
    if ($patched instanceof WP_Error) {
        return $patched;
    }
    $design = $patched['design'];

    // The patched document is validated whole, not just the parts that changed. A
    // valid replacement node can still leave the document invalid — a section removed
    // out from under its children, for one.
    $report = nibwp_paper_pro_validate_design($design);
    $refusal = nibwp_paper_pro_design_error($report);
    if ($refusal instanceof WP_Error) {
        return $refusal;
    }

    $payload = [
        'design'         => $design,
        'design_id'      => (int) ($stored['design_id'] ?? $input['design_id'] ?? 0),
        'builder'        => (string) ($input['builder'] ?? $stored['builder'] ?? 'auto'),
        'push_mode'      => 'update',
        'target_post_id' => $post_id,
        'refined_parts'  => $patched['changed'],
    ];

    $preview = nibwp_paper_pro_delegate('preview_convert', $payload);
    if ($preview instanceof WP_Error) {
        return $preview;
    }

    $preview_errors = array_values((array) ($preview['errors'] ?? []));
    if ($preview_errors !== [] || (array_key_exists('ok', $preview) && empty($preview['ok']))) {
        return [
            'ok'      => false,
            'written' => false,
            'dry_run' => $dry_run,
            'stage'   => 'preview',
            'post_id' => $post_id,
            'changed' => $patched['changed'],
            'removed' => $patched['removed'],
            'errors'  => $preview_errors,
            'preview' => $preview,
            'delegated_to' => nibwp_paper_pro_delegations(),
            'next_step'    => __('The integration could not convert the patched design. Fix what the preview names — nothing was written.', 'nibwp'),
        ];
    }

    if ($dry_run) {
        return [
            'ok'      => true,
            'written' => false,
            'dry_run' => true,
            'stage'   => 'preview',
            'post_id' => $post_id,
            'changed' => $patched['changed'],
            'removed' => $patched['removed'],
            'removed_node_count' => $patched['removed_node_count'],
            'preview'  => $preview,
            'warnings' => $report['warnings'],
            'delegated_to' => nibwp_paper_pro_delegations(),
            'next_step'    => __('Nothing was written. Confirm the parts listed in changed and removed, then call again with dry_run false.', 'nibwp'),
        ];
    }

    $converted = nibwp_paper_pro_delegate('convert', $payload);
    if ($converted instanceof WP_Error) {
        return $converted;
    }

    return [
        'ok'      => true,
        'written' => true,
        'dry_run' => false,
        'stage'   => 'converted',
        'post_id' => $post_id,
        'changed' => $patched['changed'],
        'removed' => $patched['removed'],
        'removed_node_count' => $patched['removed_node_count'],
        'converted' => $converted,
        'warnings'  => $report['warnings'],
        'delegated_to' => nibwp_paper_pro_delegations(),
        'next_step'    => __('Read the page back with nibwp/paper-pro-audit. Only the parts named above were touched; anything edited by hand elsewhere on the page is still there.', 'nibwp'),
    ];
}

/**
 * Apply the edits to a stored NDO document.
 *
 * @param array<string,mixed> $design
 * @param array<int,mixed>    $edits
 * @return array{design:array<string,mixed>,changed:array<int,string>,removed:array<int,string>,removed_node_count:int}|WP_Error
 */
function nibwp_paper_pro_apply_edits(array $design, array $edits)
{
    $changed = [];
    $removed = [];
    $removed_nodes = 0;

    foreach ($edits as $edit) {
        if (!is_array($edit)) {
            return new WP_Error(
                'nibwp_paper_pro_bad_edit',
                __('Each edit must be an object: {part, node?, style?, layout?, text?, remove?}.', 'nibwp')
            );
        }

        $part = trim((string) ($edit['part'] ?? ''));
        if ($part === '') {
            return new WP_Error(
                'nibwp_paper_pro_bad_edit',
                __('An edit with no part names nothing. Give the layer name or the NDO path the plan reported.', 'nibwp')
            );
        }

        $matches = nibwp_paper_pro_find_parts($design['root'] ?? [], 'root', $part);
        if ($matches === []) {
            $available = [];
            nibwp_paper_pro_collect_part_names($design['root'] ?? [], 'root', $available);

            return new WP_Error(
                'nibwp_paper_pro_no_such_part',
                sprintf(
                    /* translators: 1: the part asked for, 2: comma-separated part names that do exist */
                    __('No part of this design is called "%1$s". The parts it has are: %2$s. An edit that matches nothing is refused rather than reported as applied.', 'nibwp'),
                    $part,
                    implode(', ', array_slice($available, 0, 40))
                )
            );
        }

        if (!empty($edit['node'])) {
            $sub = nibwp_paper_pro_validate_subtree($edit['node'], 'edit[' . $part . '].node');
            $bad = nibwp_paper_pro_design_error($sub);
            if ($bad instanceof WP_Error) {
                return $bad;
            }
        }

        foreach ($matches as $path) {
            $applied = nibwp_paper_pro_apply_edit_at($design, $path, $edit, $removed_nodes);
            if ($applied instanceof WP_Error) {
                return $applied;
            }
            $design = $applied;
            if (!empty($edit['remove'])) {
                $removed[] = $path;
                continue;
            }
            $changed[] = $path;
        }
    }

    return [
        'design'             => $design,
        'changed'            => array_values(array_unique($changed)),
        'removed'            => array_values(array_unique($removed)),
        'removed_node_count' => $removed_nodes,
    ];
}

/**
 * Which parts an edit names. A path matches one node; a layer name matches every node
 * carrying it, which is the honest reading of "change the card heading" when there are
 * six cards.
 *
 * @param mixed $node
 * @return array<int,string>
 */
function nibwp_paper_pro_find_parts($node, string $path, string $part): array
{
    if (!is_array($node)) {
        return [];
    }

    $out = [];
    if ($path === $part) {
        $out[] = $path;
    } elseif ((string) ($node['name'] ?? '') === $part) {
        $out[] = $path;
    }

    $index = 0;
    foreach ((array) ($node['children'] ?? []) as $child) {
        foreach (nibwp_paper_pro_find_parts($child, $path . '.children[' . $index . ']', $part) as $deeper) {
            $out[] = $deeper;
        }
        $index++;
    }

    return $out;
}

/**
 * Every addressable part, for the refusal message.
 *
 * @param mixed                $node
 * @param array<int,string>    $names
 */
function nibwp_paper_pro_collect_part_names($node, string $path, array &$names): void
{
    if (!is_array($node)) {
        return;
    }
    $name = (string) ($node['name'] ?? '');
    $label = $name !== '' ? $name : $path;
    if (!in_array($label, $names, true)) {
        $names[] = $label;
    }
    $index = 0;
    foreach ((array) ($node['children'] ?? []) as $child) {
        nibwp_paper_pro_collect_part_names($child, $path . '.children[' . $index . ']', $names);
        $index++;
    }
}

/**
 * Apply one edit at one path.
 *
 * Paths are of the form root.children[2].children[0], which is how the plan and the
 * audit report them, so the caller can copy one out of a report rather than counting.
 *
 * @param array<string,mixed> $design
 * @param array<string,mixed> $edit
 * @return array<string,mixed>|WP_Error
 */
function nibwp_paper_pro_apply_edit_at(array $design, string $path, array $edit, int &$removed_nodes)
{
    $indices = [];
    if (preg_match_all('/children\[(\d+)\]/', $path, $m) > 0) {
        foreach ($m[1] as $index) {
            $indices[] = (int) $index;
        }
    }

    if ($indices === []) {
        if (!empty($edit['remove'])) {
            return new WP_Error(
                'nibwp_paper_pro_bad_edit',
                __('That edit would remove the root of the design, which leaves nothing to convert. Remove the page instead, or name a part inside it.', 'nibwp')
            );
        }
        $patched = nibwp_paper_pro_patch_node((array) ($design['root'] ?? []), $edit);
        if ($patched instanceof WP_Error) {
            return $patched;
        }
        $design['root'] = $patched;

        return $design;
    }

    // Walked by reference rather than rebuilt, so every sibling and every untouched
    // branch is the same array it was.
    $cursor = &$design['root'];
    $last = array_pop($indices);
    foreach ($indices as $index) {
        if (!isset($cursor['children'][$index]) || !is_array($cursor['children'][$index])) {
            unset($cursor);

            return new WP_Error(
                'nibwp_paper_pro_no_such_part',
                sprintf(
                    /* translators: %s: the NDO path */
                    __('The path %s does not exist in the stored design any more. Read the page again with nibwp/paper-pro-audit for current paths.', 'nibwp'),
                    $path
                )
            );
        }
        $cursor = &$cursor['children'][$index];
    }

    if (!isset($cursor['children'][$last]) || !is_array($cursor['children'][$last])) {
        unset($cursor);

        return new WP_Error(
            'nibwp_paper_pro_no_such_part',
            sprintf(
                /* translators: %s: the NDO path */
                __('The path %s does not exist in the stored design any more. Read the page again with nibwp/paper-pro-audit for current paths.', 'nibwp'),
                $path
            )
        );
    }

    if (!empty($edit['remove'])) {
        $removed_nodes += nibwp_paper_pro_count_subtree($cursor['children'][$last]);
        unset($cursor['children'][$last]);
        // Reindexed, because children is a list and a hole in it is not an NDO array
        // any more once it has been through JSON.
        $cursor['children'] = array_values($cursor['children']);
        unset($cursor);

        return $design;
    }

    $patched = nibwp_paper_pro_patch_node($cursor['children'][$last], $edit);
    if ($patched instanceof WP_Error) {
        unset($cursor);

        return $patched;
    }
    $cursor['children'][$last] = $patched;
    unset($cursor);

    return $design;
}

/**
 * One node, patched.
 *
 * @param array<string,mixed> $node
 * @param array<string,mixed> $edit
 * @return array<string,mixed>|WP_Error
 */
function nibwp_paper_pro_patch_node(array $node, array $edit)
{
    $did_something = false;

    if (!empty($edit['node']) && is_array($edit['node'])) {
        // The replacement keeps the original's name unless it brought one, so a part
        // addressed by name is still addressable by that name afterwards.
        $replacement = $edit['node'];
        if (!isset($replacement['name']) || (string) $replacement['name'] === '') {
            $replacement['name'] = (string) ($node['name'] ?? '');
        }

        return $replacement;
    }

    foreach (['style', 'layout'] as $bag) {
        if (!isset($edit[$bag]) || !is_array($edit[$bag])) {
            continue;
        }
        $current = is_array($node[$bag] ?? null) ? $node[$bag] : [];
        foreach ($edit[$bag] as $property => $value) {
            if (!is_string($property) || $property === '') {
                continue;
            }
            if ($value === null) {
                unset($current[$property]);
                $did_something = true;
                continue;
            }
            $current[$property] = $value;
            $did_something = true;
        }
        $node[$bag] = $current;
    }

    if (array_key_exists('text', $edit)) {
        if ((string) ($node['type'] ?? '') !== 'text') {
            return new WP_Error(
                'nibwp_paper_pro_bad_edit',
                sprintf(
                    /* translators: 1: the part name, 2: the node type */
                    __('"%1$s" is a %2$s, not a text node, so it has no copy to rewrite. Target the text layer inside it.', 'nibwp'),
                    (string) ($node['name'] ?? ''),
                    (string) ($node['type'] ?? '?')
                )
            );
        }
        $node['text'] = (string) $edit['text'];
        $did_something = true;
    }

    if (!$did_something) {
        return new WP_Error(
            'nibwp_paper_pro_bad_edit',
            sprintf(
                /* translators: %s: the part name */
                __('The edit for "%s" changes nothing. Give node, style, layout, text, or remove: true.', 'nibwp'),
                (string) ($node['name'] ?? '')
            )
        );
    }

    return $node;
}

/** How many nodes a subtree holds, counting itself. */
function nibwp_paper_pro_count_subtree($node): int
{
    if (!is_array($node)) {
        return 0;
    }
    $total = 1;
    foreach ((array) ($node['children'] ?? []) as $child) {
        $total += nibwp_paper_pro_count_subtree($child);
    }

    return $total;
}
