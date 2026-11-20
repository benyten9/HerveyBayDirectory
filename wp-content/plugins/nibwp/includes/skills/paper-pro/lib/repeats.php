<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Paper Pro — finding the thing that appears nine times.
 *
 * A design shows six pricing cards because a designer drew six pricing cards. A
 * page should not contain six pricing cards; it should contain one, repeated by a
 * loop over the posts, or one component placed six times. Convert the drawing
 * literally and the client gets a page where changing the card means changing it
 * six times, and the seventh plan means rebuilding the section.
 *
 * Detection is structural and deliberately blind to content. Two cards with
 * different headings, different prices and different photographs are the same card;
 * the whole point is to notice that. So a node's signature is built from its shape —
 * its normalised type, its element tag, which CSS properties it declares (not what
 * they are set to), and the signatures of its children in order — and never from a
 * name, a string of copy or a colour. Names in particular are the trap: a designer
 * who named them "Card 1".."Card 6" has given a false difference, and a designer who
 * named them all "Card" has given a true similarity that a name match would also
 * find in two unrelated sections.
 *
 * What comes out is a recommendation, not a transformation. Three or more siblings
 * of one shape is a loop; two is a component with two placements; the same shape in
 * two different parents is a component whatever the counts. Which of those is right
 * depends on where the content lives, and this file does not know that — the plan
 * says so, and the person reading it decides.
 */

/**
 * The signature of a node's shape, ignoring everything about its content.
 */
function nibwp_paper_pro_shape_signature(array $node): string
{
    $parts = [
        (string) ($node['type'] ?? '?'),
        (string) ($node['tag'] ?? ''),
    ];

    foreach (['style', 'layout'] as $bag) {
        $keys = [];
        if (is_array($node[$bag] ?? null)) {
            foreach ($node[$bag] as $property => $value) {
                if (is_string($property)) {
                    $keys[] = $property;
                }
                unset($value);
            }
        }
        sort($keys);
        $parts[] = $bag . ':' . implode(',', $keys);
    }

    $children = [];
    foreach ((array) ($node['children'] ?? []) as $child) {
        if (is_array($child)) {
            $children[] = nibwp_paper_pro_shape_signature($child);
        }
    }
    $parts[] = '(' . implode('|', $children) . ')';

    return substr(md5(implode(';', $parts)), 0, 12);
}

/**
 * A short human description of a node's shape, for the report. A hash tells the
 * reader nothing about what was found.
 */
function nibwp_paper_pro_shape_outline(array $node, int $depth = 0): string
{
    $label = (string) ($node['type'] ?? '?');
    if (($node['tag'] ?? '') !== '') {
        $label .= '<' . (string) $node['tag'] . '>';
    }
    if ($depth >= 2) {
        $kids = count((array) ($node['children'] ?? []));

        return $kids > 0 ? $label . '[+' . $kids . ']' : $label;
    }

    $children = [];
    foreach ((array) ($node['children'] ?? []) as $child) {
        if (is_array($child)) {
            $children[] = nibwp_paper_pro_shape_outline($child, $depth + 1);
        }
    }
    if ($children === []) {
        return $label;
    }

    return $label . ' > (' . implode(' + ', $children) . ')';
}

/**
 * Find the repeats.
 *
 * @param array<string,mixed> $design
 * @return array{groups:array<int,array<string,mixed>>,loop_candidates:int,component_candidates:int,duplicated_nodes:int}
 */
function nibwp_paper_pro_find_repeats(array $design): array
{
    $root = $design['root'] ?? null;
    if (!is_array($root)) {
        return ['groups' => [], 'loop_candidates' => 0, 'component_candidates' => 0, 'duplicated_nodes' => 0];
    }

    // Sibling groups first — the ones that become a loop.
    $sibling_groups = [];
    nibwp_paper_pro_collect_sibling_groups($root, 'root', $sibling_groups);

    // Then every shape, anywhere, so a card that appears in two sections is found
    // too. A shape with one child node is not interesting: every design repeats
    // plain text nodes and reporting them is noise that buries the real finding.
    $all = [];
    nibwp_paper_pro_collect_shapes($root, 'root', $all);

    $groups = [];
    $seen_signatures = [];

    foreach ($sibling_groups as $group) {
        if ($group['count'] < 2) {
            continue;
        }
        $kind = $group['count'] >= 3 ? 'loop' : 'component';
        $groups[] = [
            'kind'         => $kind,
            'scope'        => 'siblings',
            'signature'    => $group['signature'],
            'count'        => $group['count'],
            'parent_path'  => $group['parent_path'],
            'outline'      => $group['outline'],
            'names'        => $group['names'],
            'paths'        => array_slice($group['paths'], 0, 10),
            'why'          => $kind === 'loop'
                ? sprintf(
                    /* translators: 1: how many, 2: the shape outline */
                    __('%1$d siblings share one shape (%2$s). Build it once and repeat it with a loop bound to whatever supplies the content — a post type, a taxonomy, a repeater — rather than emitting the subtree %1$d times.', 'nibwp'),
                    $group['count'],
                    $group['outline']
                )
                : sprintf(
                    /* translators: %s: the shape outline */
                    __('Two siblings share one shape (%s). One reusable component placed twice, so a change lands in both.', 'nibwp'),
                    $group['outline']
                ),
        ];
        $seen_signatures[$group['signature']] = true;
    }

    foreach ($all as $signature => $record) {
        if (isset($seen_signatures[$signature])) {
            continue;
        }
        if ($record['count'] < 2 || $record['node_count'] < 2) {
            continue;
        }
        if (count(array_unique($record['parents'])) < 2) {
            continue; // Same parent and not caught as siblings: nothing new to say.
        }
        $groups[] = [
            'kind'        => 'component',
            'scope'       => 'across_the_design',
            'signature'   => (string) $signature,
            'count'       => $record['count'],
            'parent_path' => '',
            'outline'     => $record['outline'],
            'names'       => array_slice(array_values(array_unique($record['names'])), 0, 10),
            'paths'       => array_slice($record['paths'], 0, 10),
            'why'         => sprintf(
                /* translators: 1: how many times, 2: the shape outline */
                __('The same shape appears %1$d times in different parts of the design (%2$s). That is a component, not a coincidence — build it once and place it.', 'nibwp'),
                $record['count'],
                $record['outline']
            ),
        ];
    }

    // The design may already say so. An instance in Paper is an instance for a
    // reason, and converting it to a duplicate throws away a decision the designer
    // already made.
    $instances = [];
    foreach ($all as $record) {
        foreach ($record['instance_names'] as $name) {
            $instances[$name] = ($instances[$name] ?? 0) + 1;
        }
    }
    foreach ($instances as $name => $count) {
        if ($count < 2) {
            continue;
        }
        $groups[] = [
            'kind'        => 'declared_component',
            'scope'       => 'declared_in_the_design',
            'signature'   => 'instance:' . md5((string) $name),
            'count'       => (int) $count,
            'parent_path' => '',
            'outline'     => (string) $name,
            'names'       => [(string) $name],
            'paths'       => [],
            'why'         => sprintf(
                /* translators: 1: the component name, 2: how many instances */
                __('"%1$s" is already an instance in the design and appears %2$d times. The designer made it reusable; keep it reusable.', 'nibwp'),
                (string) $name,
                (int) $count
            ),
        ];
    }

    usort($groups, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

    $loops = 0;
    $components = 0;
    $duplicated = 0;
    foreach ($groups as $group) {
        if ($group['kind'] === 'loop') {
            $loops++;
        } else {
            $components++;
        }
        $duplicated += max(0, (int) $group['count'] - 1);
    }

    return [
        'groups'               => $groups,
        'loop_candidates'      => $loops,
        'component_candidates' => $components,
        'duplicated_nodes'     => $duplicated,
    ];
}

/**
 * Groups of siblings that share a shape.
 *
 * @param array<int,array<string,mixed>> $groups
 */
function nibwp_paper_pro_collect_sibling_groups(array $node, string $path, array &$groups): void
{
    $children = (array) ($node['children'] ?? []);
    $by_signature = [];
    $index = 0;

    foreach ($children as $child) {
        if (!is_array($child)) {
            $index++;
            continue;
        }
        $child_path = $path . '.children[' . $index . ']';
        // A bare text node or an empty box repeats everywhere. Only shapes with
        // something inside them are worth a recommendation.
        if (count((array) ($child['children'] ?? [])) > 0) {
            $signature = nibwp_paper_pro_shape_signature($child);
            if (!isset($by_signature[$signature])) {
                $by_signature[$signature] = [
                    'signature'   => $signature,
                    'count'       => 0,
                    'parent_path' => $path,
                    'outline'     => nibwp_paper_pro_shape_outline($child),
                    'names'       => [],
                    'paths'       => [],
                ];
            }
            $by_signature[$signature]['count']++;
            $name = (string) ($child['name'] ?? '');
            if ($name !== '' && count($by_signature[$signature]['names']) < 10) {
                $by_signature[$signature]['names'][] = $name;
            }
            if (count($by_signature[$signature]['paths']) < 10) {
                $by_signature[$signature]['paths'][] = $child_path;
            }
        }
        nibwp_paper_pro_collect_sibling_groups($child, $child_path, $groups);
        $index++;
    }

    foreach ($by_signature as $group) {
        if ($group['count'] >= 2) {
            $groups[] = $group;
        }
    }
}

/**
 * Every shape in the tree, with where it was seen.
 *
 * @param array<string,array<string,mixed>> $shapes
 */
function nibwp_paper_pro_collect_shapes(array $node, string $path, array &$shapes, string $parent = ''): void
{
    if (count((array) ($node['children'] ?? [])) > 0) {
        $signature = nibwp_paper_pro_shape_signature($node);
        if (!isset($shapes[$signature])) {
            $shapes[$signature] = [
                'count'          => 0,
                'node_count'     => nibwp_paper_pro_count_nodes($node),
                'outline'        => nibwp_paper_pro_shape_outline($node),
                'names'          => [],
                'paths'          => [],
                'parents'        => [],
                'instance_names' => [],
            ];
        }
        $shapes[$signature]['count']++;
        $shapes[$signature]['parents'][] = $parent;
        $name = (string) ($node['name'] ?? '');
        if ($name !== '') {
            $shapes[$signature]['names'][] = $name;
            if ((string) ($node['type'] ?? '') === 'component_instance') {
                $shapes[$signature]['instance_names'][] = $name;
            }
        }
        if (count($shapes[$signature]['paths']) < 10) {
            $shapes[$signature]['paths'][] = $path;
        }
    }

    $index = 0;
    foreach ((array) ($node['children'] ?? []) as $child) {
        if (is_array($child)) {
            nibwp_paper_pro_collect_shapes($child, $path . '.children[' . $index . ']', $shapes, $path);
        }
        $index++;
    }
}

/** How many nodes a subtree holds, counting itself. */
function nibwp_paper_pro_count_nodes(array $node): int
{
    $total = 1;
    foreach ((array) ($node['children'] ?? []) as $child) {
        if (is_array($child)) {
            $total += nibwp_paper_pro_count_nodes($child);
        }
    }

    return $total;
}
