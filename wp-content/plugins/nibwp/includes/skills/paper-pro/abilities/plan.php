<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/../lib/delegate.php';
require_once __DIR__ . '/../lib/validate.php';
require_once __DIR__ . '/../lib/tokens.php';
require_once __DIR__ . '/../lib/repeats.php';
require_once __DIR__ . '/../lib/copy.php';
require_once __DIR__ . '/../lib/builder.php';

/**
 * Paper Pro — the plan, before anything exists.
 *
 * This is the step that earns the skill its price, and it is read-only on purpose.
 * The questions it answers are the ones that are cheap now and expensive after the
 * page exists: which builder, what repeats, which words are real, which values the
 * site already has a name for, what happens on a phone, and — the one nobody writes
 * down — what this conversion is not going to manage.
 *
 * It also reports what arrived. The agent read the canvas; nothing on this side saw
 * it. So the plan opens with a count of nodes, frames, text layers and characters of
 * copy, because that count is the only evidence the handover was complete, and a
 * number that looks too small is worth seeing before a page is built on it rather
 * than after.
 */

wp_register_ability('nibwp/paper-pro-plan', [
    'label'       => __('Paper Pro — Plan the conversion', 'nibwp'),
    'description' => 'Given a design handed over from Paper as an NDO 1.0 document, produce the conversion plan with nothing written: what arrived and whether it looks complete, which builder to target and why, which repeated parts should become one loop or one component, which text is real copy and which is standing in, which colours and type sizes already have a token on this site, the responsive story, and what this conversion cannot do. Read-only.',
    'category'    => 'paper-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'design' => [
                'type'        => 'object',
                'description' => 'The NDO 1.0 document you built from what Paper\'s MCP tools returned: {ndo_version:"1.0", target:{type,title}, tokens, root, warnings}. Nothing here can fetch it — Paper has no cloud API and the MCP server is on your machine, not the site\'s.',
            ],
            'site_tokens' => [
                'type'        => 'object',
                'description' => 'The tokens this site defines, as the integration\'s list_tokens action returns them. Read them first; without them every literal counts as unmatched.',
            ],
            'builder' => [
                'type'        => 'string',
                'enum'        => ['auto', 'etchwp', 'bricks', 'elementor', 'gutenberg'],
                'description' => 'Target builder, or auto to use whatever this site already builds with.',
            ],
            'available_builders' => [
                'type'        => 'array',
                'items'       => ['type' => 'object'],
                'description' => 'What the integration\'s list_builders action reported, if you already have it. Fetched here when omitted.',
            ],
            'purpose' => [
                'type'        => 'string',
                'description' => 'What this page is for, in the user\'s words. Shapes the recommendations — a landing page and a documentation page want different answers to the same tree.',
            ],
            'breakpoints' => [
                'type'        => 'array',
                'items'       => ['type' => 'integer'],
                'description' => 'The widths this site designs for, if they are not the usual ones. Reported back in the responsive story.',
            ],
        ],
        'required' => ['design'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_paper_pro_plan_ability',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Read the design out of Paper first, with Paper\'s own MCP tools, on the machine where Paper Desktop is running. There is no API key and no cloud API for Paper files; WordPress cannot do this part and will not pretend to.',
                'Normalise what Paper returned into NDO 1.0 before calling this. The shape is in the input description and the vocabulary is the one the builder emitters already read.',
                'Call the integration\'s list_tokens action before this one and pass the result as site_tokens, or every colour and size in the design is reported as having no token when it may well have one.',
                'Show the plan to the user before converting. The counts at the top are the only evidence the handover was complete; a design that arrived with four nodes did not arrive.',
                'The cannot_do list is not boilerplate. Read it out when it matters — particularly that nothing here can verify the page against the Paper file afterwards.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_paper_pro_plan_ability(array $input)
{
    // Read-only, so no capability beyond the licence: a plan writes nothing and
    // seeing one cannot hurt anybody.
    $gate = nibwp_paper_pro_gate(false, false);
    if ($gate instanceof WP_Error) {
        return $gate;
    }

    $envelope = nibwp_paper_pro_validate_payload($input);
    if ($envelope instanceof WP_Error) {
        return $envelope;
    }

    $design = (array) $input['design'];
    $report = nibwp_paper_pro_validate_design($design);
    $refusal = nibwp_paper_pro_design_error($report);
    if ($refusal instanceof WP_Error) {
        // A plan over a broken tree would be a plan for a page that cannot be
        // built. Refused here, where it is still a node path.
        return $refusal;
    }

    $site_tokens = (array) ($input['site_tokens'] ?? []);
    $notes = [];
    if ($site_tokens === []) {
        $fetched = nibwp_paper_pro_delegate('list_tokens');
        if ($fetched instanceof WP_Error) {
            // Degraded rather than refused: a plan without token matching is still
            // worth reading, and this ability writes nothing either way.
            $notes[] = sprintf(
                /* translators: %s: the error message from the integration */
                __('The site\'s tokens could not be read (%s), so nothing was matched against them. Pass site_tokens yourself for the matching half of this plan.', 'nibwp'),
                $fetched->get_error_message()
            );
        } else {
            $site_tokens = (array) ($fetched['tokens'] ?? $fetched);
        }
    }

    $builders = $input['available_builders'] ?? null;
    if (!is_array($builders)) {
        $listed = nibwp_paper_pro_delegate('list_builders');
        $builders = $listed instanceof WP_Error ? [] : (array) ($listed['builders'] ?? $listed);
        if ($listed instanceof WP_Error) {
            $notes[] = sprintf(
                /* translators: %s: the error message from the integration */
                __('The site\'s builders could not be read (%s), so the target below is a recommendation rather than a reading of this install.', 'nibwp'),
                $listed->get_error_message()
            );
        }
    }

    $builder = nibwp_paper_pro_choose_builder((string) ($input['builder'] ?? 'auto'), (array) $builders);
    $repeats = nibwp_paper_pro_find_repeats($design);
    $copy = nibwp_paper_pro_classify_copy((array) $report['text_nodes']);
    $tokens = nibwp_paper_pro_match_tokens($design, $site_tokens);
    $responsive = nibwp_paper_pro_responsive_story($design, (array) ($input['breakpoints'] ?? []));

    $recommendations = array_merge(
        $tokens['recommendations'],
        $copy['recommendations'],
        $responsive['recommendations']
    );

    return [
        'ok'        => true,
        'written'   => false,
        'purpose'   => (string) ($input['purpose'] ?? ''),
        'target'    => (array) ($design['target'] ?? []),
        'handover'  => [
            'counts'  => $report['counts'],
            'types'   => $report['types'],
            'depth'   => $report['depth'],
            'verdict' => nibwp_paper_pro_handover_verdict((array) $report['counts'], (int) $report['depth']),
            'note'    => __('Nothing on the WordPress side saw the Paper file, so these numbers are the only evidence the handover was complete. If they look small for the frame you read, read it again before converting.', 'nibwp'),
        ],
        'builder'    => $builder,
        'repeats'    => $repeats,
        'copy'       => $copy,
        'tokens'     => $tokens,
        'responsive' => $responsive,
        'cannot_do'  => nibwp_paper_pro_limitations(),
        'warnings'   => array_merge((array) ($design['warnings'] ?? []), (array) $report['warnings']),
        'notes'      => $notes,
        'recommendations' => $recommendations,
        'delegated_to'    => nibwp_paper_pro_delegations(),
        'next_step'       => __('Show this to the user, then call nibwp/paper-pro-convert with the same design and dry_run true. Do not re-author the design between the two calls — the plan describes the tree that was validated here.', 'nibwp'),
    ];
}

/**
 * Does the handover look like a whole design?
 *
 * @param array<string,int> $counts
 */
function nibwp_paper_pro_handover_verdict(array $counts, int $depth): string
{
    $nodes = (int) ($counts['nodes'] ?? 0);

    if ($nodes <= 3 || $depth <= 2) {
        return __('Thin. This is a handful of nodes — either a very small frame or a read that stopped at the top level. Check it before converting.', 'nibwp');
    }
    if ((int) ($counts['text'] ?? 0) === 0) {
        return __('Structure but no copy. Possible, and much more often a handover that returned layers without their characters.', 'nibwp');
    }
    if ((int) ($counts['characters'] ?? 0) < 40) {
        return __('Almost no copy for this many nodes. Worth a second look at what Paper returned.', 'nibwp');
    }

    return __('Plausible for a complete frame — nodes, structure and copy all arrived. Still the only evidence there is.', 'nibwp');
}

/**
 * The responsive story, read off the tree rather than invented.
 *
 * @param array<string,mixed> $design
 * @param array<int,mixed>    $breakpoints
 * @return array<string,mixed>
 */
function nibwp_paper_pro_responsive_story(array $design, array $breakpoints): array
{
    $widths = [];
    $rows = [];
    $wide_rows = [];
    $fixed = [];

    $root = $design['root'] ?? null;
    if (is_array($root)) {
        nibwp_paper_pro_collect_responsive($root, 'root', $widths, $rows, $wide_rows, $fixed);
    }

    $design_width = $widths === [] ? 0 : (int) round(max($widths));
    $points = [];
    foreach ($breakpoints as $point) {
        if (is_numeric($point)) {
            $points[] = (int) $point;
        }
    }
    if ($points === []) {
        $points = [1024, 768, 480];
    }
    sort($points);

    $recommendations = [];
    if ($design_width === 0) {
        $recommendations[] = [
            'rule'    => 'no_geometry',
            'message' => __('No node carried a bbox, so there is no design width to reason from and the responsive story below is generic. Include absolute geometry in the handover if Paper reports it — it is what tells a row of three cards from a row of twelve.', 'nibwp'),
        ];
    }
    if ($wide_rows !== []) {
        $recommendations[] = [
            'rule'    => 'rows_that_must_stack',
            'message' => sprintf(
                /* translators: %d: how many rows hold three or more children */
                __('%d horizontal row(s) hold three or more children. Those are the ones that break first on a phone — give each one a wrap or a stack rule rather than letting the builder shrink the columns to nothing.', 'nibwp'),
                count($wide_rows)
            ),
        ];
    }
    if ($fixed !== []) {
        $recommendations[] = [
            'rule'    => 'fixed_widths',
            'message' => sprintf(
                /* translators: %d: how many nodes carry a fixed width */
                __('%d node(s) carry a fixed pixel width. A design is drawn at one width; a page is not. Convert these to a max-width or a flex basis unless the value is genuinely fixed, like an icon.', 'nibwp'),
                count($fixed)
            ),
        ];
    }

    return [
        'design_width'   => $design_width,
        'breakpoints'    => $points,
        'horizontal_rows' => count($rows),
        'rows_to_stack'  => array_slice($wide_rows, 0, 20),
        'fixed_width_nodes' => array_slice($fixed, 0, 20),
        'story'          => $design_width > 0
            ? sprintf(
                /* translators: 1: the design width in pixels, 2: comma-separated breakpoints */
                __('The design was drawn at about %1$dpx. Everything below that has to be decided here rather than in the design: rows stack, type steps down through the site\'s own text tokens, and the padding scale comes from the token system. Plan for %2$s.', 'nibwp'),
                $design_width,
                implode('px, ', $points) . 'px'
            )
            : __('With no geometry in the handover, the responsive behaviour is whatever the builder\'s own defaults do. Decide it explicitly instead: which rows stack, at what width, and which type sizes step down.', 'nibwp'),
        'recommendations' => $recommendations,
    ];
}

/**
 * @param array<int,float>              $widths
 * @param array<int,array<string,mixed>> $rows
 * @param array<int,array<string,mixed>> $wide_rows
 * @param array<int,array<string,mixed>> $fixed
 */
function nibwp_paper_pro_collect_responsive(array $node, string $path, array &$widths, array &$rows, array &$wide_rows, array &$fixed): void
{
    if (isset($node['bbox']['w']) && is_numeric($node['bbox']['w'])) {
        $widths[] = (float) $node['bbox']['w'];
    }

    $layout = is_array($node['layout'] ?? null) ? $node['layout'] : [];
    $style = is_array($node['style'] ?? null) ? $node['style'] : [];
    $children = (array) ($node['children'] ?? []);

    if (($layout['flex-direction'] ?? '') === 'row' && count($children) >= 2) {
        $row = [
            'path'     => $path,
            'name'     => (string) ($node['name'] ?? ''),
            'children' => count($children),
            'wraps'    => ($layout['flex-wrap'] ?? '') === 'wrap',
        ];
        $rows[] = $row;
        if (count($children) >= 3 && !$row['wraps']) {
            $wide_rows[] = $row;
        }
    }

    foreach (['width', 'min-width'] as $property) {
        $value = $style[$property] ?? $layout[$property] ?? null;
        if (is_string($value) && preg_match('/^\d{3,}px$/', trim($value)) === 1) {
            $fixed[] = ['path' => $path, 'name' => (string) ($node['name'] ?? ''), 'property' => $property, 'value' => trim($value)];
        }
    }

    $index = 0;
    foreach ($children as $child) {
        if (is_array($child)) {
            nibwp_paper_pro_collect_responsive($child, $path . '.children[' . $index . ']', $widths, $rows, $wide_rows, $fixed);
        }
        $index++;
    }
}

/**
 * What this conversion cannot do. Written down because the honest list is short and
 * saying it once beats discovering it one item at a time.
 *
 * @return array<int,array<string,string>>
 */
function nibwp_paper_pro_limitations(): array
{
    return [
        [
            'limit' => 'no_verification_against_the_source',
            'why'   => __('Nothing on the WordPress side can re-read your Paper file. There is no cloud API for Paper files and no credential for one, so the handover is the only copy — the page can be checked for internal consistency, never against the design itself. The Figma path can image-diff against the file; this one cannot, and will not claim to.', 'nibwp'),
        ],
        [
            'limit' => 'not_headless',
            'why'   => __('This needs the machine Paper Desktop is running on, because that is where the MCP server lives. There is no scheduled import and no server-side conversion — if the designer is not in the loop, there is no design.', 'nibwp'),
        ],
        [
            'limit' => 'interaction_and_motion',
            'why'   => __('Prototype links, hover states, transitions and animation are not part of NDO and are not converted. They have to be rebuilt in the builder, and the plan should say which ones the design implies.', 'nibwp'),
        ],
        [
            'limit' => 'absolute_overlap',
            'why'   => __('Auto-layout becomes flex cleanly. Overlapping nodes positioned by eye become an approximation — they are the parts most worth looking at on the built page.', 'nibwp'),
        ],
        [
            'limit' => 'assets_are_separate',
            'why'   => __('Images and vectors are references until the integration\'s attach_asset action brings them into the Media Library. A converted page with no assets attached shows empty boxes, which looks like a conversion failure and is not one.', 'nibwp'),
        ],
        [
            'limit' => 'fonts',
            'why'   => __('A font named in the design is a name, not a licence. If the site does not already load it the page falls back, and no conversion can fix that from here.', 'nibwp'),
        ],
    ];
}
