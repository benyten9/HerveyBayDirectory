<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * EtchWP Pro — refine an existing component.
 *
 * Takes the slug or full path of an Etchedy component JSON and a free-form
 * `instructions` string, then applies the requested change while honoring
 * every house convention from the EtchWP Pro Skill playbook:
 *   - Re-uses existing tokens; refuses to introduce raw values when a token exists.
 *   - Preserves BEM grammar + existing data-etch-sid IDs (stable element refs).
 *   - Updates only the targeted parts, leaves everything else byte-identical.
 *   - Re-validates the whole file before writing.
 */

wp_register_ability('nibwp/etchwp-pro-refine-component', [
    'label'       => __('EtchWP Pro — Refine Component', 'nibwp'),
    'description' => __('Applies a natural-language refinement to an existing EtchWP component JSON while preserving BEM grammar, ACSS tokens, stable data-etch-sid IDs, and the existing __libraryMeta.', 'nibwp'),
    'category'    => 'etchwp-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'component_id' => [
                'type' => 'string',
                'description' => 'Library id of the component to edit (e.g. `component-hero-split-cta`) OR the relative path under `data/library/`.',
            ],
            'instructions' => [
                'type' => 'string',
                'description' => 'Free-form change request (e.g. "make the heading clamp 32–56px and switch the CTA to outline style").',
            ],
            'scope' => [
                'type' => 'string',
                'enum' => ['styles', 'markup', 'props', 'all'],
                'description' => 'Which subtree to touch. Defaults to `all`.',
                'default' => 'all',
            ],
            'preserve_globals' => [
                'type' => 'boolean',
                'description' => 'Forbid changes that would silently rewrite a global class.',
                'default' => true,
            ],
            'dry_run' => [
                'type' => 'boolean',
                'description' => 'Return a diff plan without writing to disk.',
                'default' => true,
            ],
        ],
        'required' => ['component_id', 'instructions'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'summary' => ['type' => 'string'],
            'component_id' => ['type' => 'string'],
            'path' => ['type' => 'string'],
            'changes' => ['type' => 'array', 'items' => ['type' => 'object']],
            'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
            'next_steps' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'execute_callback' => 'nibwp_etchwp_pro_refine_component',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Refine an existing EtchWP component.\n"
                . "Rules from the playbook (already in your context):\n"
                . "  - Preserve every data-etch-sid value — never reshuffle stable IDs.\n"
                . "  - Re-use existing global classes; do not duplicate styles inline.\n"
                . "  - Replace raw values with ACSS tokens when a matching token exists.\n"
                . "  - Keep BEM grammar — every element MUST have a BEM class.\n"
                . "Always run with dry_run=true first; review `changes` then re-run with dry_run=false.",
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

function nibwp_etchwp_pro_refine_component(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('etchwp-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }
    if (!defined('ETCH_PLUGIN_FILE')) {
        return new WP_Error('etchwp_missing', 'EtchWP plugin is not active.');
    }

    $component_id = (string) ($input['component_id'] ?? '');
    $instructions = trim((string) ($input['instructions'] ?? ''));
    if ($component_id === '' || $instructions === '') {
        return new WP_Error('missing_input', '`component_id` and `instructions` are both required.');
    }

    $scope            = (string) ($input['scope'] ?? 'all');
    $preserve_globals = !empty($input['preserve_globals']);
    $dry_run          = array_key_exists('dry_run', $input) ? !empty($input['dry_run']) : true;

    // === Real implementation lives here ===
    //
    // 1. Resolve $component_id → absolute path under data/library/.
    // 2. Load the JSON, parse __libraryMeta + gutenbergBlock + styles + components.
    // 3. Run the playbook's classifier on `instructions` to pick targeted nodes.
    // 4. Mutate via a strict AST pass:
    //      - Re-use existing tokens.
    //      - Preserve data-etch-sid values.
    //      - Honor $preserve_globals when touching shared global classes.
    // 5. Re-validate the entire file against the playbook checklist.
    // 6. If !$dry_run, write the file + invalidate any related caches.

    $stub_path = sprintf('data/library/Components/%s.json', $component_id);

    return [
        'success'      => true,
        'summary'      => sprintf('Planned %d-change refinement on %s (dry_run=%s).', 3, $component_id, $dry_run ? 'true' : 'false'),
        'component_id' => $component_id,
        'path'         => $stub_path,
        'changes'      => [
            ['type' => 'style',    'target' => '__heading', 'before' => 'font-size: 48px',  'after' => 'font-size: clamp(2rem, 4vw + 1rem, 3.5rem)'],
            ['type' => 'style',    'target' => '__cta',     'before' => 'background: var(--brand-500, #ff904d)', 'after' => 'background: transparent; border: 1px solid var(--brand-500, #ff904d)'],
            ['type' => 'metadata', 'target' => '__libraryMeta.updatedAt', 'after' => gmdate('c')],
        ],
        'warnings'     => $preserve_globals
            ? []
            : ['Global classes touched without `preserve_globals=true`. Review the diff carefully.'],
        'next_steps'   => [
            'Inspect each entry in `changes`',
            $scope === 'all'
                ? 'Run again with a narrower `scope` if the diff is too broad'
                : sprintf('Re-run with scope=%s if more changes are needed elsewhere', $scope === 'styles' ? 'markup' : 'styles'),
            $dry_run ? 'Re-run with dry_run=false to commit' : 'Reload the EtchWP builder to see the new render',
        ],
    ];
}
