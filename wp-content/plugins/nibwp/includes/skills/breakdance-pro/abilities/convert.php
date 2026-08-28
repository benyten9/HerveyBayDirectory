<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . "/../lib/registry.php";
require_once __DIR__ . "/../lib/tokens.php";
require_once __DIR__ . "/../lib/tree.php";
require_once __DIR__ . "/../lib/validator.php";
require_once __DIR__ . "/../lib/figma.php";

/**
 * Breakdance Pro — conversion abilities.
 *
 * Four entry points, one handler. HTML, a URL, an image and a Figma frame are
 * different things to look at and the same thing to write: the agent does the
 * seeing, this does the checking and the writing.
 *
 * Synthesis is deliberately agent-side. A converter written in PHP would have
 * to reimplement layout understanding badly; the model already has it. What the
 * model does not have is this site's element registry, its variables, its
 * selectors and its conventions — which is exactly what this file enforces.
 *
 * dry_run defaults to TRUE. A conversion that writes on the first call gives
 * nobody a chance to see the recommendations first.
 */

/**
 * The shared input schema.
 *
 * Kept in a function rather than repeated four times so the four abilities
 * cannot drift apart, which is how one of them ends up accepting a field the
 * handler no longer reads.
 */
function nibwp_bdpro_convert_schema(string $source_label): array
{
    return [
        'type' => 'object',
        'properties' => [
            'source' => [
                'type' => 'object',
                'description' => $source_label,
                'properties' => [
                    'html'  => ['type' => 'string'],
                    'url'   => ['type' => 'string'],
                    'notes' => ['type' => 'string', 'description' => 'What the agent observed in the source — layout, hierarchy, anything the payload cannot express.'],
                ],
            ],
            'nodes' => [
                'type' => 'array',
                'description' => 'The converted design as a flat node list. Each node: {ref, type, parent, properties}. ref is your own name for the node; parent names another node\'s ref, or null for a top-level node. type is a registered Breakdance slug such as "EssentialElements\\\\Section" — note the escaped backslash.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'ref'        => ['type' => 'string'],
                        'type'       => ['type' => 'string'],
                        'parent'     => ['type' => ['string', 'null']],
                        'properties' => ['type' => 'object'],
                    ],
                    'required' => ['ref', 'type'],
                ],
            ],
            'target' => [
                'type' => 'object',
                'description' => 'Where to write it.',
                'properties' => [
                    'post_id' => ['type' => 'integer', 'description' => 'Existing post, page or template to write into.'],
                    'mode'    => ['type' => 'string', 'enum' => ['replace', 'append'], 'default' => 'replace', 'description' => 'replace swaps the whole tree; append adds the nodes after what is already there.'],
                    'role'    => ['type' => 'string', 'enum' => ['page', 'template', 'header', 'footer', 'popup', 'block'], 'default' => 'page'],
                    'title'   => ['type' => 'string', 'description' => 'Title when creating something new rather than writing into post_id.'],
                ],
            ],
            'dry_run' => ['type' => 'boolean', 'default' => true, 'description' => 'True validates and reports without writing. Run it this way first.'],
        ],
        'required' => ['nodes'],
        'additionalProperties' => false,
    ];
}

/** The annotations block every conversion ability shares. */
function nibwp_bdpro_convert_meta(string $instructions): array
{
    return [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => $instructions,
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ];
}

$nibwp_bdpro_common_instructions = implode("\n", [
    'Read nibwp/breakdance-pro-elements and nibwp/breakdance-pro-tokens BEFORE converting. Element slugs and variables differ per site and guessing them produces a page that renders blank.',
    'Element slugs carry a namespace: EssentialElements\\Heading. In JSON the backslash must be doubled.',
    'Run with dry_run true first, show the user the recommendations, then run again with dry_run false.',
    'Repeated cards are a loop, not five copies. Accept the use_loop recommendation unless the user says otherwise.',
]);

wp_register_ability('nibwp/breakdance-pro-html-to-section', [
    'label'       => __('Breakdance Pro — HTML to section', 'nibwp'),
    'description' => __('Convert HTML into a validated Breakdance section, page, header, footer, popup or global block. Checks every element slug and property path against this site\'s own registry, maps literal colors onto existing variables, and turns repeated markup into a loop. Trigger words: "convert to breakdance", "html to breakdance", "breakdance this".', 'nibwp'),
    'category'    => 'breakdance-pro',
    'input_schema' => nibwp_bdpro_convert_schema('The HTML being converted from.'),
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_bdpro_convert',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => nibwp_bdpro_convert_meta($nibwp_bdpro_common_instructions),
]);

wp_register_ability('nibwp/breakdance-pro-url-to-section', [
    'label'       => __('Breakdance Pro — URL to section', 'nibwp'),
    'description' => __('Rebuild a page from a live URL as a validated Breakdance section or template, using this site\'s elements and design tokens rather than the source site\'s markup.', 'nibwp'),
    'category'    => 'breakdance-pro',
    'input_schema' => nibwp_bdpro_convert_schema('The URL being rebuilt, and what was found there.'),
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_bdpro_convert',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => nibwp_bdpro_convert_meta(
        $nibwp_bdpro_common_instructions . "\n" .
        'Rebuild the structure, not the source markup. Copying another site\'s classes and wrappers produces something that cannot be edited in the builder afterwards.'
    ),
]);

wp_register_ability('nibwp/breakdance-pro-image-to-section', [
    'label'       => __('Breakdance Pro — Image to section', 'nibwp'),
    'description' => __('Turn a screenshot, mockup or design image into a validated Breakdance section built from real elements and this site\'s design tokens.', 'nibwp'),
    'category'    => 'breakdance-pro',
    'input_schema' => nibwp_bdpro_convert_schema('What the image showed.'),
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_bdpro_convert',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => nibwp_bdpro_convert_meta(
        $nibwp_bdpro_common_instructions . "\n" .
        'From an image you are inferring spacing and sizes. Prefer this site\'s existing variables over the exact pixel values you estimate — they will be closer to right than your estimate.'
    ),
]);

/**
 * Figma takes the shared schema plus a `figma` argument carrying what was read
 * from the file. That argument is not decoration — the handler refuses to treat
 * a frame as a picture when the file could have been read properly.
 */
$nibwp_bdpro_figma_schema = nibwp_bdpro_convert_schema('The Figma frame being converted.');
$nibwp_bdpro_figma_schema['properties']['figma'] = [
    'type' => 'object',
    'description' => 'What nibwp/figma-pro-fetch returned for this frame: the node tree, frame geometry, auto-layout modes and Variables. Pass it through as it came back — this is what separates a real conversion from guessing at a screenshot.',
    'properties' => [
        'file_key'  => ['type' => 'string'],
        'node_id'   => ['type' => 'string'],
        'nodes'     => ['type' => ['array', 'object'], 'description' => 'The node tree.'],
        'frames'    => ['type' => ['array', 'object'], 'description' => 'Frames with their names and geometry.'],
        'tokens'    => ['type' => ['array', 'object'], 'description' => 'Figma Variables / styles, as name → value.'],
        'variables' => ['type' => ['array', 'object']],
    ],
];
$nibwp_bdpro_figma_schema['properties']['allow_image_fallback'] = [
    'type' => 'boolean',
    'default' => false,
    'description' => 'Only set true when no Figma connection exists and the user has been told the output will be lower fidelity. It is refused while a connection is available.',
];
$nibwp_bdpro_figma_schema['required'] = ['nodes'];

wp_register_ability('nibwp/breakdance-pro-figma-to-section', [
    'label'       => __('Breakdance Pro — Figma to section', 'nibwp'),
    'description' => __('Convert a Figma frame into a validated Breakdance section from the FILE — node tree, auto-layout and Variables — not from a rendered image. Figma Variables are matched against this site\'s own variables by value, so the section adopts the design system rather than copying hex codes.', 'nibwp'),
    'category'    => 'breakdance-pro',
    'input_schema' => $nibwp_bdpro_figma_schema,
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_bdpro_convert_figma',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => nibwp_bdpro_convert_meta(
        $nibwp_bdpro_common_instructions . "\n" .
        'Read the frame with nibwp/figma-pro-fetch FIRST and pass the result as the figma argument. A frame is a structure: auto-layout says why spacing is what it is, and Variables name the colors. Looking at a picture of it throws all of that away and leaves you estimating numbers you could have known.' . "\n" .
        'This ability checks the figma argument for evidence of a real read — node tree, geometry, auto-layout, Variables. If a Figma account is connected and you send an image-derived payload anyway, it refuses.'
    ),
]);

/**
 * The Figma handler.
 *
 * Everything before the delegation is about one question: was the file read, or
 * merely looked at? Getting that wrong is invisible in the output — the section
 * builds, it just quietly has estimated spacing and hex codes where the design
 * system had names.
 *
 * @return array|WP_Error
 */
function nibwp_bdpro_convert_figma(array $input)
{
    $status = nibwp_bdpro_figma_status();
    $figma = (array) ($input['figma'] ?? []);
    $assessment = nibwp_bdpro_figma_assess($figma);
    $allow_image = (bool) ($input['allow_image_fallback'] ?? false);

    // A connection exists and the file was not read. Refused rather than
    // warned: the good path was available, and a section built from a picture
    // is indistinguishable from a real one until someone edits the palette and
    // it fails to follow.
    if ($status['can_read_structure'] && !$assessment['structural']) {
        return [
            'ok'         => false,
            'written'    => false,
            'error'      => 'figma_not_structural',
            'message'    => __('This site can read Figma files, but the payload carries no evidence of a structural read — so this would be a screenshot conversion wearing a Figma label.', 'nibwp'),
            'missing'    => $assessment['missing'],
            'figma'      => $status,
            'next_steps' => nibwp_bdpro_figma_next_steps($status),
        ];
    }

    // No connection. Allowed, once, and only with the fallback acknowledged —
    // and the response says plainly what was given up.
    if (!$status['can_read_structure'] && !$assessment['structural'] && !$allow_image) {
        return [
            'ok'         => false,
            'written'    => false,
            'error'      => 'figma_not_connected',
            'message'    => __('No Figma connection on this site, so the frame can only be treated as a picture. Tell the user that spacing and colors will be estimated rather than read, then call again with allow_image_fallback true — or connect Figma and get the real thing.', 'nibwp'),
            'figma'      => $status,
            'next_steps' => nibwp_bdpro_figma_next_steps($status),
        ];
    }

    $result = nibwp_bdpro_convert($input);

    if ($result instanceof WP_Error || !is_array($result)) {
        return $result;
    }

    $result['figma'] = [
        'structural' => $assessment['structural'],
        'evidence'   => $assessment['evidence'],
        'connection' => $status,
    ];

    if ($assessment['structural']) {
        $bridge = nibwp_bdpro_figma_token_bridge($figma);
        $result['figma']['tokens'] = $bridge;

        foreach ($bridge['matched'] as $pair) {
            $result['recommendations'][] = [
                'rule'    => 'figma_token_bridge',
                'message' => sprintf(
                    /* translators: 1: Figma variable name, 2: Breakdance variable name */
                    __('Figma\'s "%1$s" is the same value as this site\'s "%2$s" — use the site variable so the section follows the palette.', 'nibwp'),
                    $pair['figma'],
                    $pair['breakdance']
                ),
            ];
        }

        if ($bridge['unmatched'] !== []) {
            $result['recommendations'][] = [
                'rule'    => 'figma_tokens_unmatched',
                'message' => sprintf(
                    /* translators: %d: number of Figma variables with no match */
                    __('%d Figma Variables have no equivalent on this site. Say which, and let the user decide whether to add them to the design system — do not invent them.', 'nibwp'),
                    count($bridge['unmatched'])
                ),
                'unmatched' => $bridge['unmatched'],
            ];
        }
    } else {
        $result['warnings'][] = [
            'rule'    => 'figma_image_fallback',
            'message' => __('Built from a picture of the frame, not the file. Spacing, sizes and colors are estimates. Connecting Figma would make this exact.', 'nibwp'),
        ];
    }

    return $result;
}

/**
 * The shared handler.
 *
 * @return array|WP_Error
 */
function nibwp_bdpro_convert(array $input)
{
    if (!function_exists('nibwp_breakdance_available') || !nibwp_breakdance_available()) {
        return new WP_Error(
            'nibwp_bdpro_missing',
            __('Breakdance is not active on this site, so there is nothing to build into.', 'nibwp')
        );
    }

    $nodes = array_values((array) ($input['nodes'] ?? []));
    if ($nodes === []) {
        return new WP_Error('nibwp_bdpro_empty', __('No nodes were supplied.', 'nibwp'));
    }

    $target = (array) ($input['target'] ?? []);
    $role = (string) ($target['role'] ?? 'page');
    $dry_run = !array_key_exists('dry_run', $input) || (bool) $input['dry_run'];

    $report = nibwp_bdpro_validate($nodes, ['template_role' => $role]);

    if ($report['errors'] !== []) {
        return [
            'ok'              => false,
            'written'         => false,
            'errors'          => $report['errors'],
            'warnings'        => $report['warnings'],
            'recommendations' => $report['recommendations'],
            'next_step'       => __('Fix the errors and call again. Nothing was written.', 'nibwp'),
        ];
    }

    $post_id = (int) ($target['post_id'] ?? 0);
    $mode = (string) ($target['mode'] ?? 'replace');

    // Appending needs to know which IDs are taken, so the offset is read from
    // the live tree before anything is built.
    $start_id = 0;
    $existing = null;

    if ($post_id > 0 && $mode === 'append') {
        $existing = nibwp_breakdance_get_tree($post_id);
        if ($existing instanceof WP_Error) {
            return $existing;
        }
        $start_id = nibwp_breakdance_max_id($existing);
    }

    $built = nibwp_bdpro_build_tree($nodes, $start_id);

    if ($built['errors'] !== []) {
        return [
            'ok'      => false,
            'written' => false,
            'errors'  => array_map(static fn(string $m): array => ['rule' => 'structure', 'message' => $m], $built['errors']),
            'warnings' => $report['warnings'],
        ];
    }

    $summary = [
        'nodes'    => count($nodes),
        'root_children' => count($built['tree']['root']['children'] ?? []),
        'elements' => array_values(array_unique(array_map(
            static fn(array $n): string => (string) ($n['type'] ?? ''),
            $nodes
        ))),
    ];

    if ($dry_run) {
        return [
            'ok'              => true,
            'written'         => false,
            'dry_run'         => true,
            'summary'         => $summary,
            'warnings'        => $report['warnings'],
            'recommendations' => $report['recommendations'],
            'tree_preview'    => $built['tree'],
            'next_step'       => __('Show the recommendations to the user, then call again with dry_run false to write it.', 'nibwp'),
        ];
    }

    // ── Write ────────────────────────────────────────────────────────────
    if ($post_id <= 0) {
        $created = nibwp_bdpro_create_target($role, (string) ($target['title'] ?? ''));
        if ($created instanceof WP_Error) {
            return $created;
        }
        $post_id = $created;
    }

    $tree = $built['tree'];

    if ($mode === 'append' && is_array($existing)) {
        $tree = $existing;
        foreach ($built['tree']['root']['children'] as $child) {
            $tree['root']['children'][] = $child;
        }
    }

    $written = nibwp_breakdance_put_tree($post_id, $tree);
    if ($written instanceof WP_Error) {
        return $written;
    }

    return [
        'ok'              => true,
        'written'         => true,
        'post_id'         => $post_id,
        'mode'            => $mode,
        'summary'         => $summary,
        'warnings'        => $report['warnings'],
        'recommendations' => $report['recommendations'],
        'edit_url'        => admin_url('post.php?post=' . $post_id . '&action=edit'),
        'view_url'        => get_permalink($post_id),
    ];
}

/**
 * Create the post to write into when none was named.
 *
 * @return int|WP_Error
 */
function nibwp_bdpro_create_target(string $role, string $title)
{
    $post_type = $role === 'page' ? 'page' : nibwp_breakdance_post_type($role);

    if ($post_type === '') {
        return new WP_Error('nibwp_bdpro_bad_role', __('That is not a Breakdance post type on this install.', 'nibwp'));
    }

    $post_id = wp_insert_post([
        'post_type'   => $post_type,
        'post_title'  => $title !== '' ? $title : __('Untitled', 'nibwp'),
        // Drafted on purpose: a generated page should be looked at before it is
        // public, and publishing it is one click for whoever asked.
        'post_status' => $role === 'page' ? 'draft' : 'publish',
    ], true);

    return is_wp_error($post_id) ? $post_id : (int) $post_id;
}
