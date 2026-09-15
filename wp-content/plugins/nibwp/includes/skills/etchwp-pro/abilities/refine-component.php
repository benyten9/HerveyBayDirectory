<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * EtchWP Pro — refine an existing component.
 *
 * Takes a component identifier and a free-form
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
                'description' => 'Identifier of the component to edit. This ability is not implemented and always refuses — see its error for the route that works.',
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

    // This ability was never implemented, and until now it did not say so.
    //
    // It returned success:true with three hard-coded `changes` — a heading at
    // 48px and a --brand-500 of #ff904d — describing edits to a component it
    // had not read, on a file it never opened. Worse, dry_run=false returned
    // the same success while writing nothing, so a caller was told a commit
    // had happened when no byte had moved. A customer reported planned edits
    // against values that existed nowhere in their component; the values were
    // these literals.
    //
    // An unimplemented ability must fail. Refusing loudly costs the caller one
    // round trip; a fabricated diff costs them their trust in every other
    // result this skill returns.
    unset($scope, $preserve_globals, $dry_run);

    return new WP_Error(
        'refine_not_implemented',
        sprintf(
            'Refining "%s" through this ability is not implemented — it cannot read or write component files, and any diff it returned would be invented. '
            . 'Use the working route instead: read the component (nibwp/wp-get-post for a page, or the component\'s wp_block post), apply the change yourself, '
            . 'then submit the whole payload through nibwp/etchwp-pro-html-to-component, which validates it and writes it. That path is covered by the validator; this one is not.',
            $component_id
        ),
        ['status' => 501]
    );
}
