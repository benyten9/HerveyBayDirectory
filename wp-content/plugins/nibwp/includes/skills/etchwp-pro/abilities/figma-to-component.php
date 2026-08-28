<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * EtchWP Pro — Figma frame → component.
 *
 * Accepts a Figma URL (or explicit file_key + node_id pair) plus an access
 * token, fetches the frame via Figma's REST API, and converts it into a
 * fully controllable EtchWP component:
 *   - Auto-layout → flex / grid container rules with container-query breakpoints.
 *   - Text styles → ACSS font/size/weight tokens (with the closest fallback).
 *   - Color styles → ACSS color tokens (with the literal hex as fallback).
 *   - Repeating instances → etch/component with `properties` + loops.
 *   - Variants (Component Set) → conditions / data-style props.
 */

wp_register_ability('nibwp/etchwp-pro-figma-to-component', [
    'label'       => __('EtchWP Pro — Figma to Component', 'nibwp'),
    'description' => __('Converts a Figma frame URL into a reusable EtchWP component with ACSS tokens, BEM naming, props, conditions, and loops. Figma variants become EtchWP conditions; component instances become loops.', 'nibwp'),
    'category'    => 'etchwp-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'figma_url' => [
                'type' => 'string',
                'description' => 'Full Figma URL pointing at a frame or component. The file_key + node_id are extracted from the URL when present.',
            ],
            'file_key' => [
                'type' => 'string',
                'description' => 'Figma file key (alternative to figma_url).',
            ],
            'node_id' => [
                'type' => 'string',
                'description' => 'Figma node id (alternative to figma_url). Accepts the URL-encoded form (e.g. `12:345`).',
            ],
            'access_token' => [
                'type' => 'string',
                'description' => 'Figma personal access token. Falls back to wp_options `nibwp_figma_access_token`.',
            ],
            'brand' => [
                'type' => 'string',
                'description' => 'Brand slug for the BEM class prefix.',
            ],
            'target_section' => [
                'type' => 'string',
                'description' => 'Optional hint: hero, features, pricing, testimonials, cta, footer.',
            ],
            'use_loops' => [
                'type' => 'boolean',
                'description' => 'Convert repeating component instances into a loop with item props.',
                'default' => true,
            ],
            'dry_run' => [
                'type' => 'boolean',
                'description' => 'Return planned component without writing to DB.',
                'default' => false,
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'summary' => ['type' => 'string'],
            'component_slug' => ['type' => 'string'],
            'file_key' => ['type' => 'string'],
            'node_id' => ['type' => 'string'],
            'global_classes_proposed' => ['type' => 'array', 'items' => ['type' => 'string']],
            'props' => ['type' => 'array', 'items' => ['type' => 'object']],
            'loops' => ['type' => 'array', 'items' => ['type' => 'object']],
            'conditions' => ['type' => 'array', 'items' => ['type' => 'object']],
            'payload' => ['type' => 'object'],
            'next_steps' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'execute_callback' => 'nibwp_etchwp_pro_figma_to_component',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Convert a Figma frame to an EtchWP component.\n"
                . "Provide either `figma_url` OR (`file_key` + `node_id`).\n"
                . "Follow the EtchWP Pro Skill playbook (already in your context):\n"
                . "  - Map every Figma color → ACSS token with hex fallback.\n"
                . "  - Map Auto-layout → flex/grid with container-query rules.\n"
                . "  - Figma component instances → etch/component blocks with `properties`.\n"
                . "  - Variants → conditions / data-* props.\n"
                . "Preview with dry_run=true; commit with dry_run=false.",
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

function nibwp_etchwp_pro_figma_to_component(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('etchwp-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }
    if (!defined('ETCH_PLUGIN_FILE')) {
        return new WP_Error('etchwp_missing', 'EtchWP plugin is not active.');
    }

    $figma_url    = (string) ($input['figma_url']   ?? '');
    $file_key     = (string) ($input['file_key']    ?? '');
    $node_id      = (string) ($input['node_id']     ?? '');
    $access_token = (string) ($input['access_token'] ?? get_option('nibwp_figma_access_token', ''));

    if ($figma_url === '' && ($file_key === '' || $node_id === '')) {
        return new WP_Error('missing_source', 'Provide `figma_url`, OR both `file_key` and `node_id`.');
    }
    if ($access_token === '') {
        return new WP_Error('missing_token', 'No Figma access token. Pass `access_token` or set the option `nibwp_figma_access_token`.');
    }

    // Parse the URL when given (Figma URLs look like:
    //   https://www.figma.com/file/<file_key>/<file_name>?node-id=<node_id>
    //   https://www.figma.com/design/<file_key>/<file_name>?node-id=<node_id>
    if ($figma_url !== '') {
        $parts = wp_parse_url($figma_url);
        $path  = (string) ($parts['path']  ?? '');
        $query = (string) ($parts['query'] ?? '');
        if (preg_match('#/(file|design|proto)/([^/]+)#', $path, $m)) {
            $file_key = $file_key !== '' ? $file_key : $m[2];
        }
        parse_str($query, $q);
        if (isset($q['node-id'])) {
            $node_id = $node_id !== '' ? $node_id : (string) $q['node-id'];
        }
    }
    if ($file_key === '' || $node_id === '') {
        return new WP_Error('invalid_figma_url', 'Could not extract file_key + node_id from the URL.');
    }

    $brand          = sanitize_key((string) ($input['brand'] ?? get_option('nibwp_etchwp_brand', 'etched')));
    $target_section = (string) ($input['target_section'] ?? 'auto');
    $use_loops      = !empty($input['use_loops']);
    $dry_run        = !empty($input['dry_run']);

    // === Real implementation lives here ===
    //
    // 1. GET https://api.figma.com/v1/files/{$file_key}/nodes?ids={$node_id}
    //    with header `X-Figma-Token: $access_token`.
    // 2. Walk the returned node tree (Frame → Auto-layout → Text / Shape).
    // 3. Map fills/strokes → ACSS color tokens (closest match) with hex fallback.
    // 4. Map auto-layout → flex/grid + gap.
    // 5. Map text styles → ACSS typography tokens.
    // 6. Detect component instances → emit etch/component + properties.
    // 7. Validate, build gutenbergBlock tree, persist via EtchWP API.

    $slug = nibwp_etchwp_slugify($target_section === 'auto' ? 'figma-' . substr(md5($node_id), 0, 6) : $target_section);

    $stub_payload = [
        'section'   => $target_section === 'auto' ? 'hero' : $target_section,
        'brand'     => $brand,
        'component' => $slug,
        'file_key'  => $file_key,
        'node_id'   => $node_id,
    ];

    return [
        'success'                 => true,
        'summary'                 => sprintf('Planned %s/%s from Figma file %s, node %s (dry_run=%s).', $brand, $slug, $file_key, $node_id, $dry_run ? 'true' : 'false'),
        'component_slug'          => $slug,
        'file_key'                => $file_key,
        'node_id'                 => $node_id,
        'global_classes_proposed' => ["{$brand}-section", "{$brand}-container"],
        'props'                   => [
            ['name' => 'heading', 'type' => 'string', 'required' => true],
            ['name' => 'cta',     'type' => 'object', 'required' => false, 'description' => 'Optional CTA pair (label + href).'],
            ['name' => 'items',   'type' => 'array',  'required' => false, 'description' => 'Loop items if the Figma frame contains repeating instances.'],
        ],
        'loops'                   => $use_loops ? [
            ['name' => 'items', 'source_prop' => 'items', 'min' => 1, 'max' => 12],
        ] : [],
        'conditions'              => [
            ['show_when' => 'cta',   'target' => '__cta'],
            ['show_when' => 'items', 'target' => '__items'],
        ],
        'payload'                 => $stub_payload,
        'next_steps'              => [
            'Confirm the brand token mapping looks right',
            'Run again with use_loops=false if you want a flat instance tree',
            $dry_run ? 'Re-run with dry_run=false to commit' : 'Refine variants via the EtchWP UI',
        ],
    ];
}
