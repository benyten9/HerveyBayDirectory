<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Paper Pro — validating what came out of Paper, before it becomes a page.
 *
 * This is the file the whole skill exists for. In the Figma path WordPress fetches
 * the document itself, so the thing it parses is the thing the designer drew. Here
 * the design arrives as an argument, assembled by an agent out of whatever Paper's
 * MCP tools returned, and nothing on this side can go and check. There is no
 * second copy to compare against and no token to fetch one with.
 *
 * Which leaves two honest jobs, and it is worth being clear about which is which:
 *
 *   1. SHAPE, which can be proved. NDO 1.0 is a contract the builder emitters
 *      already read — ndo_version, target, tokens, root, warnings, and nodes of
 *      {figma_type, name, type, style, layout, children, bbox}. A tree that breaks
 *      it is refused with the path of the node that broke it. Emitters do not
 *      tolerate surprises: an unknown `type` is not a styling question, it is a
 *      section that renders as nothing.
 *
 *   2. COMPLETENESS, which cannot be proved and is therefore counted. A handover
 *      that quietly dropped two frames is a perfectly valid NDO. So the validator
 *      counts what arrived — nodes, frames, text nodes, characters of copy, images
 *      — and warns on the shapes that mean something probably went missing: a text
 *      node with no text in it, a container with no children, a tree one level
 *      deep. Those are warnings, not errors, because any of them can be legitimate;
 *      what is not legitimate is converting without saying so.
 *
 * On the key named figma_type: it is named for the tool that defined this format
 * first, and it is not renamed here. What it holds for a Paper handover is Paper's
 * own node kind, whatever the MCP tools call it — the raw word from the source
 * tool, kept for traceability. The normalised `type` is the one the emitters switch
 * on, and that vocabulary is fixed.
 */

/**
 * The normalised node vocabulary the builder emitters switch on. Fixed by
 * class-figma-normalize.php's map_type(); adding to it here without adding to the
 * emitters would produce a page with a hole in it.
 *
 * @return array<int,string>
 */
function nibwp_paper_pro_node_types(): array
{
    return ['section', 'container', 'component', 'component_instance', 'text', 'shape'];
}

/** The target kinds a conversion can land as. */
function nibwp_paper_pro_target_types(): array
{
    return ['page', 'post', 'template', 'block'];
}

/**
 * A tree deeper or wider than this did not come from a design; it came from a bug,
 * and recursing it to find out is how a validator becomes the outage.
 */
function nibwp_paper_pro_limits(): array
{
    return ['max_depth' => 60, 'max_nodes' => 5000];
}

/**
 * Validate an NDO document.
 *
 * @param array<string,mixed> $design
 * @return array{passed:bool,errors:array<int,array<string,string>>,warnings:array<int,array<string,string>>,counts:array<string,int>,types:array<string,int>,depth:int,text_nodes:array<int,array<string,mixed>>}
 */
function nibwp_paper_pro_validate_design(array $design): array
{
    $errors = [];
    $warnings = [];
    $limits = nibwp_paper_pro_limits();

    $version = $design['ndo_version'] ?? null;
    if (!is_string($version) || $version === '') {
        $errors[] = [
            'path'    => 'ndo_version',
            'rule'    => 'missing_version',
            'message' => __('The design has no ndo_version. An NDO 1.0 document declares its version so the emitters know what they are reading — set it to "1.0".', 'nibwp'),
        ];
    } elseif ($version !== '1.0') {
        $errors[] = [
            'path'    => 'ndo_version',
            'rule'    => 'unknown_version',
            'message' => sprintf(
                /* translators: %s: the version string that was given */
                __('ndo_version is "%s". This skill and the builder emitters speak 1.0; converting a version they do not know would produce a page nobody can predict.', 'nibwp'),
                $version
            ),
        ];
    }

    $target = $design['target'] ?? null;
    if (!is_array($target)) {
        $errors[] = [
            'path'    => 'target',
            'rule'    => 'missing_target',
            'message' => __('The design has no target. Give target as {type, title} so the conversion knows what it is making and what to call it.', 'nibwp'),
        ];
    } else {
        $type = $target['type'] ?? null;
        if (!is_string($type) || !in_array($type, nibwp_paper_pro_target_types(), true)) {
            $errors[] = [
                'path'    => 'target.type',
                'rule'    => 'bad_target_type',
                'message' => sprintf(
                    /* translators: 1: the value given, 2: comma-separated valid values */
                    __('target.type is %1$s. It must be one of: %2$s.', 'nibwp'),
                    is_string($type) ? '"' . $type . '"' : gettype($type),
                    implode(', ', nibwp_paper_pro_target_types())
                ),
            ];
        }
        $title = $target['title'] ?? null;
        if (!is_string($title) || trim($title) === '') {
            $errors[] = [
                'path'    => 'target.title',
                'rule'    => 'missing_target_title',
                'message' => __('target.title is empty. A converted design with no title is a page called "Auto Draft" that nobody will find again — name it after the frame it came from.', 'nibwp'),
            ];
        }
    }

    foreach (['tokens', 'warnings'] as $key) {
        if (array_key_exists($key, $design) && !is_array($design[$key])) {
            $errors[] = [
                'path'    => $key,
                'rule'    => 'not_an_array',
                'message' => sprintf(
                    /* translators: 1: NDO key, 2: the type that was given */
                    __('%1$s must be an array, got %2$s.', 'nibwp'),
                    $key,
                    gettype($design[$key])
                ),
            ];
        }
    }

    $counts = ['nodes' => 0, 'frames' => 0, 'text' => 0, 'images' => 0, 'characters' => 0, 'components' => 0];
    $types = [];
    $text_nodes = [];
    $depth = 0;

    $root = $design['root'] ?? null;
    if (!is_array($root)) {
        $errors[] = [
            'path'    => 'root',
            'rule'    => 'missing_root',
            'message' => __('The design has no root node. Whatever Paper returned, the handover arrived with no tree in it — read the frame again rather than converting an empty document.', 'nibwp'),
        ];
    } else {
        nibwp_paper_pro_validate_node($root, 'root', 1, $errors, $warnings, $counts, $types, $text_nodes, $depth, $limits);
    }

    // Completeness signals. Each of these can be legitimate, so none of them stops
    // a conversion; all of them stop a conversion being described as finished.
    if ($counts['nodes'] > 0 && $counts['text'] === 0) {
        $warnings[] = [
            'path'    => 'root',
            'rule'    => 'no_text_at_all',
            'message' => __('Not one text node arrived. A design with no copy in it is possible, but it is far more often a handover that returned structure without content — check what Paper gave you before converting.', 'nibwp'),
        ];
    }
    if ($depth === 1 && $counts['nodes'] === 1) {
        $warnings[] = [
            'path'    => 'root',
            'rule'    => 'root_has_no_children',
            'message' => __('The root node has no children, so the whole design is one node. That is a frame that was read but not descended into — ask Paper for the children.', 'nibwp'),
        ];
    }

    return [
        'passed'     => $errors === [],
        'errors'     => $errors,
        'warnings'   => $warnings,
        'counts'     => $counts,
        'types'      => $types,
        'depth'      => $depth,
        'text_nodes' => $text_nodes,
    ];
}

/**
 * One node, and everything under it.
 *
 * @param array<string,mixed>                $node
 * @param array<int,array<string,string>>    $errors
 * @param array<int,array<string,string>>    $warnings
 * @param array<string,int>                  $counts
 * @param array<string,int>                  $types
 * @param array<int,array<string,mixed>>     $text_nodes
 * @param array<string,int>                  $limits
 */
function nibwp_paper_pro_validate_node(
    $node,
    string $path,
    int $level,
    array &$errors,
    array &$warnings,
    array &$counts,
    array &$types,
    array &$text_nodes,
    int &$depth,
    array $limits
): void {
    if ($level > $depth) {
        $depth = $level;
    }

    if (!is_array($node)) {
        $errors[] = [
            'path'    => $path,
            'rule'    => 'node_not_an_object',
            'message' => sprintf(
                /* translators: 1: node path, 2: the type that was found */
                __('%1$s is %2$s, not a node object. Every entry in children[] is a node.', 'nibwp'),
                $path,
                gettype($node)
            ),
        ];

        return;
    }

    if ($counts['nodes'] >= $limits['max_nodes']) {
        $errors[] = [
            'path'    => $path,
            'rule'    => 'too_many_nodes',
            'message' => sprintf(
                /* translators: %d: the node limit */
                __('More than %d nodes arrived. No frame is that big; something is repeating itself. Read a narrower part of the design.', 'nibwp'),
                (int) $limits['max_nodes']
            ),
        ];

        return;
    }
    if ($level > $limits['max_depth']) {
        $errors[] = [
            'path'    => $path,
            'rule'    => 'too_deep',
            'message' => sprintf(
                /* translators: %d: the depth limit */
                __('The tree is deeper than %d levels at this node, which means it is probably a cycle rather than a design.', 'nibwp'),
                (int) $limits['max_depth']
            ),
        ];

        return;
    }

    $counts['nodes']++;

    // figma_type carries the SOURCE tool's own word for this node. For a Paper
    // handover that is whatever Paper's MCP tools called it, which is not Figma's
    // vocabulary and is not checked against it — only required to be there, so the
    // conversion can be traced back to the thing that was read.
    $source_type = $node['figma_type'] ?? null;
    if (!is_string($source_type) || trim($source_type) === '') {
        $errors[] = [
            'path'    => $path . '.figma_type',
            'rule'    => 'missing_figma_type',
            'message' => __('figma_type is missing. It holds the source tool\'s own name for this node — Paper\'s node kind, as the MCP tools reported it. The key is named for the tool that defined NDO first; do not rename it, and do not leave it empty.', 'nibwp'),
        ];
    }

    if (!array_key_exists('name', $node) || !is_string($node['name'])) {
        $errors[] = [
            'path'    => $path . '.name',
            'rule'    => 'missing_name',
            'message' => __('name is missing or not a string. The layer name is how the plan, the refine step and the audit all address this part of the design — without it nothing can be referred to by name.', 'nibwp'),
        ];
    }

    $type = $node['type'] ?? null;
    if (!is_string($type) || $type === '') {
        $errors[] = [
            'path'    => $path . '.type',
            'rule'    => 'missing_type',
            'message' => sprintf(
                /* translators: %s: comma-separated valid types */
                __('type is missing. It is the normalised kind the builder emitters switch on, one of: %s.', 'nibwp'),
                implode(', ', nibwp_paper_pro_node_types())
            ),
        ];
    } elseif (!in_array($type, nibwp_paper_pro_node_types(), true)) {
        $errors[] = [
            'path'    => $path . '.type',
            'rule'    => 'unknown_type',
            'message' => sprintf(
                /* translators: 1: the type given, 2: comma-separated valid types */
                __('type "%1$s" is not in the NDO vocabulary. The emitters switch on this key and silently emit nothing for a value they do not know, so the symptom would be an empty section rather than an error. Map it to one of: %2$s.', 'nibwp'),
                $type,
                implode(', ', nibwp_paper_pro_node_types())
            ),
        ];
    } else {
        $types[$type] = ($types[$type] ?? 0) + 1;
        if ($type === 'section') {
            $counts['frames']++;
        }
        if ($type === 'component' || $type === 'component_instance') {
            $counts['components']++;
        }
    }

    foreach (['style', 'layout'] as $bag) {
        if (!array_key_exists($bag, $node)) {
            $errors[] = [
                'path'    => $path . '.' . $bag,
                'rule'    => 'missing_' . $bag,
                'message' => sprintf(
                    /* translators: %s: the NDO key */
                    __('%s is missing. An NDO node always carries both bags, empty if there is nothing in them — an absent bag and an empty one mean different things to the emitters.', 'nibwp'),
                    $bag
                ),
            ];
            continue;
        }
        if (!is_array($node[$bag])) {
            $errors[] = [
                'path'    => $path . '.' . $bag,
                'rule'    => 'bag_not_an_array',
                'message' => sprintf(
                    /* translators: 1: the NDO key, 2: the type given */
                    __('%1$s must be an array of CSS declarations, got %2$s.', 'nibwp'),
                    $bag,
                    gettype($node[$bag])
                ),
            ];
            continue;
        }
        foreach ($node[$bag] as $property => $value) {
            if (!is_string($property) || $property === '') {
                $errors[] = [
                    'path'    => $path . '.' . $bag,
                    'rule'    => 'bag_key_not_a_property',
                    'message' => __('A declaration in this bag has no property name. These are CSS declarations keyed by property, not a positional list.', 'nibwp'),
                ];
                continue;
            }
            if (is_array($value) || is_object($value)) {
                $errors[] = [
                    'path'    => $path . '.' . $bag . '.' . $property,
                    'rule'    => 'declaration_not_scalar',
                    'message' => sprintf(
                        /* translators: %s: the CSS property */
                        __('%s holds a structure rather than a value. A declaration is a scalar — the string that would appear after the colon in CSS.', 'nibwp'),
                        $property
                    ),
                ];
                continue;
            }
            // The house rule, enforced where the value is: ACSS text tokens with a
            // px or em fallback, never a clamp() for font-size. The Etch/ACSS
            // validator rejects it downstream, so catching it here is the
            // difference between a message and a failed build.
            if ($property === 'font-size' && is_string($value) && stripos($value, 'clamp(') !== false) {
                $errors[] = [
                    'path'    => $path . '.' . $bag . '.font-size',
                    'rule'    => 'clamp_font_size',
                    'message' => __('font-size uses clamp(), which is rejected here. Use the site\'s text token with a fallback — var(--text-l, 20px) — and let the token system handle the scale.', 'nibwp'),
                ];
            }
        }
    }

    if (isset($node['bbox'])) {
        if (!is_array($node['bbox'])) {
            $errors[] = [
                'path'    => $path . '.bbox',
                'rule'    => 'bbox_not_an_object',
                'message' => __('bbox must be {w, h} when it is present.', 'nibwp'),
            ];
        } else {
            foreach (['w', 'h'] as $side) {
                if (isset($node['bbox'][$side]) && !is_numeric($node['bbox'][$side])) {
                    $errors[] = [
                        'path'    => $path . '.bbox.' . $side,
                        'rule'    => 'bbox_not_numeric',
                        'message' => sprintf(
                            /* translators: %s: w or h */
                            __('bbox.%s must be a number.', 'nibwp'),
                            $side
                        ),
                    ];
                }
            }
        }
    }

    if (is_string($type) && $type === 'text') {
        $counts['text']++;
        if (!array_key_exists('text', $node) || !is_string($node['text'])) {
            $errors[] = [
                'path'    => $path . '.text',
                'rule'    => 'text_node_without_text',
                'message' => __('A text node with no text key. The copy is the one thing a text node is for — if Paper returned the layer without its characters, read it again rather than converting an empty heading.', 'nibwp'),
            ];
        } else {
            $counts['characters'] += mb_strlen($node['text']);
            if (trim($node['text']) === '') {
                $warnings[] = [
                    'path'    => $path . '.text',
                    'rule'    => 'empty_text',
                    'message' => sprintf(
                        /* translators: %s: the layer name */
                        __('The text layer "%s" arrived empty. It may be empty in the design; it is more often copy that did not come across.', 'nibwp'),
                        is_string($node['name'] ?? null) ? $node['name'] : $path
                    ),
                ];
            }
            $text_nodes[] = ['path' => $path, 'name' => (string) ($node['name'] ?? ''), 'text' => $node['text'], 'tag' => (string) ($node['tag'] ?? '')];
        }
        $tag = $node['tag'] ?? null;
        if ($tag !== null && (!is_string($tag) || !in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'div', 'li', 'blockquote', 'label'], true))) {
            $warnings[] = [
                'path'    => $path . '.tag',
                'rule'    => 'odd_tag',
                'message' => sprintf(
                    /* translators: %s: the tag given */
                    __('tag is %s, which is not one of the elements text normally becomes. The emitter will fall back to a paragraph.', 'nibwp'),
                    is_string($tag) ? '"' . $tag . '"' : gettype($tag)
                ),
            ];
        }
    }

    $style = is_array($node['style'] ?? null) ? $node['style'] : [];
    if (isset($style['background-image'])) {
        $counts['images']++;
    }

    if (!array_key_exists('children', $node)) {
        $errors[] = [
            'path'    => $path . '.children',
            'rule'    => 'missing_children',
            'message' => __('children is missing. A leaf node carries an empty array — an absent children key reads as a node that was never descended into.', 'nibwp'),
        ];

        return;
    }
    if (!is_array($node['children'])) {
        $errors[] = [
            'path'    => $path . '.children',
            'rule'    => 'children_not_an_array',
            'message' => sprintf(
                /* translators: %s: the type given */
                __('children must be an array, got %s.', 'nibwp'),
                gettype($node['children'])
            ),
        ];

        return;
    }

    $index = 0;
    foreach ($node['children'] as $child) {
        nibwp_paper_pro_validate_node(
            $child,
            $path . '.children[' . $index . ']',
            $level + 1,
            $errors,
            $warnings,
            $counts,
            $types,
            $text_nodes,
            $depth,
            $limits
        );
        $index++;
    }
}

/**
 * Validate one node and its subtree on its own — a replacement part handed in by the
 * refine step, which is a node rather than a document and therefore has no
 * ndo_version and no target of its own.
 *
 * @param mixed $node
 * @return array{passed:bool,errors:array<int,array<string,string>>,warnings:array<int,array<string,string>>,counts:array<string,int>,types:array<string,int>,depth:int,text_nodes:array<int,array<string,mixed>>}
 */
function nibwp_paper_pro_validate_subtree($node, string $path = 'node'): array
{
    $errors = [];
    $warnings = [];
    $counts = ['nodes' => 0, 'frames' => 0, 'text' => 0, 'images' => 0, 'characters' => 0, 'components' => 0];
    $types = [];
    $text_nodes = [];
    $depth = 0;

    nibwp_paper_pro_validate_node($node, $path, 1, $errors, $warnings, $counts, $types, $text_nodes, $depth, nibwp_paper_pro_limits());

    return [
        'passed'     => $errors === [],
        'errors'     => $errors,
        'warnings'   => $warnings,
        'counts'     => $counts,
        'types'      => $types,
        'depth'      => $depth,
        'text_nodes' => $text_nodes,
    ];
}

/**
 * The envelope, checked before the tree: is there a design here at all, and is the
 * caller asking this skill to do something it cannot do?
 *
 * Deliberately a different error code from the tree validation. "The payload is
 * wrong" and "the design is malformed" lead to different fixes, and a caller that
 * cannot tell them apart fixes the wrong one.
 *
 * @param array<string,mixed> $input
 * @return WP_Error|null
 */
function nibwp_paper_pro_validate_payload(array $input)
{
    // The one request this skill will not even try to honour. There is no cloud API
    // for Paper files and no credential to call one with; the MCP server lives on
    // the machine running Paper Desktop. Saying that is more useful than failing
    // somewhere further in with a connection error.
    foreach (['paper_url', 'paper_file_url', 'paper_file_id', 'mcp_endpoint', 'fetch_from_paper'] as $key) {
        if (!empty($input[$key]) && empty($input['design'])) {
            return new WP_Error(
                'nibwp_paper_pro_cannot_reach_paper',
                sprintf(
                    /* translators: %s: the input key the caller supplied */
                    __('"%s" was given, but WordPress cannot open a Paper file. Paper\'s MCP server is started by the Paper Desktop app and is reachable only on that machine — stdio through the paper CLI, with a legacy local HTTP endpoint — and there is no key and no cloud API for Paper files. Read the design with Paper\'s own MCP tools on the machine where Paper is open, normalise it into an NDO 1.0 document, and pass it as design.', 'nibwp'),
                    $key
                )
            );
        }
    }

    if (!isset($input['design'])) {
        return new WP_Error(
            'nibwp_paper_pro_invalid_payload',
            __('No design was handed over. Read the frame with Paper\'s own MCP tools, normalise it into an NDO 1.0 document — {ndo_version, target, tokens, root, warnings} — and pass it as design.', 'nibwp')
        );
    }
    if (!is_array($input['design'])) {
        return new WP_Error(
            'nibwp_paper_pro_invalid_payload',
            sprintf(
                /* translators: %s: the type that was given */
                __('design is %s. It must be the NDO document itself, not a string and not a reference to one — nothing here can go and look it up.', 'nibwp'),
                gettype($input['design'])
            )
        );
    }
    if ($input['design'] === []) {
        return new WP_Error(
            'nibwp_paper_pro_invalid_payload',
            __('design is empty. The handover arrived with nothing in it.', 'nibwp')
        );
    }

    return null;
}

/**
 * Turn a failed validation report into the refusal the ability returns.
 *
 * @param array<string,mixed> $report
 * @return WP_Error|null
 */
function nibwp_paper_pro_design_error(array $report)
{
    if (!empty($report['passed'])) {
        return null;
    }

    $errors = (array) ($report['errors'] ?? []);
    $lines = [];
    foreach (array_slice($errors, 0, 12) as $error) {
        $lines[] = sprintf('%s — %s', (string) ($error['path'] ?? '?'), (string) ($error['message'] ?? ''));
    }
    if (count($errors) > 12) {
        $lines[] = sprintf(
            /* translators: %d: how many further errors there are */
            __('…and %d more.', 'nibwp'),
            count($errors) - 12
        );
    }

    return new WP_Error(
        'nibwp_paper_pro_invalid_ndo',
        sprintf(
            /* translators: 1: number of problems, 2: the list of problems */
            __('The handover is not a valid NDO 1.0 document, so nothing was converted and nothing was written. %1$d problem(s):%2$s', 'nibwp'),
            count($errors),
            "\n- " . implode("\n- ", $lines)
        ),
        ['errors' => $errors]
    );
}
