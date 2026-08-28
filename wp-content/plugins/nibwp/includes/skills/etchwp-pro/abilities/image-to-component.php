<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Sample premium ability — image → EtchWP atomic component.
 *
 * This file is loaded only when:
 *   - EtchWP plugin is active (manifest's `requires`)
 *   - User has a valid Pro/Agency/Lifetime license (manifest's `premium` flag)
 *   - User toggled the skill ON in the marketplace
 *
 * Replace the callback body with your real implementation — typically calls
 * an external Vision API, parses the response, validates against the playbook
 * in instructions.md, and writes an EtchWP component via the public EtchWP API.
 */

wp_register_ability('nibwp/etchwp-pro-image-to-component', [
    'label'       => __('EtchWP Pro — Image to Component', domain: 'nibwp'),
    'description' => __('Converts an image (URL or media-library ID) into a fully controllable EtchWP atomic component using ACSS spacing tokens and global classes. Validates against the house playbook before emit.', domain: 'nibwp'),
    'category'    => 'etchwp-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'image' => [
                'type' => 'string',
                'description' => 'Image URL or numeric WP attachment ID.',
            ],
            'target_section' => [
                'type' => 'string',
                'description' => 'Optional hint: hero, features, pricing, testimonials, cta, footer.',
            ],
            'apply_globals' => [
                'type' => 'boolean',
                'description' => 'Auto-promote repeated patterns to global classes.',
                'default' => true,
            ],
            'dry_run' => [
                'type' => 'boolean',
                'description' => 'Return planned component without writing to DB.',
                'default' => false,
            ],
        ],
        'required' => ['image'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'summary' => ['type' => 'string'],
            'global_classes_proposed' => ['type' => 'array', 'items' => ['type' => 'string']],
            'payload' => ['type' => 'object'],
            'next_steps' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'execute_callback' => 'nibwp_etchwp_pro_image_to_component',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Convert an image to an EtchWP atomic component.\n"
                . "Follow the EtchWP Pro Skill playbook (already in your context).\n"
                . "Always validate: no inline styles, section-* wrappers, ACSS tokens.\n"
                . "Set dry_run=true first to preview, then dry_run=false to commit.",
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

function nibwp_etchwp_pro_image_to_component(array $input): array|WP_Error
{
    // Premium gate — locks the ability if license expired or skill disabled.
    $gate = nibwp_skill_gate('etchwp-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }

    if (!defined('ETCH_PLUGIN_FILE')) {
        return new WP_Error('etchwp_missing', 'EtchWP plugin is not active.');
    }

    $image = (string) ($input['image'] ?? '');
    if ($image === '') {
        return new WP_Error('missing_image', 'image is required (URL or attachment ID).');
    }

    $target = (string) ($input['target_section'] ?? 'auto');
    $apply_globals = !empty($input['apply_globals']);
    $dry_run = !empty($input['dry_run']);

    // === Real implementation lives here ===
    //
    // Typical flow:
    //   1. Resolve $image → public URL (handle attachment IDs)
    //   2. POST to your Vision endpoint with the playbook from instructions.md
    //   3. Validate response (no inline styles, has section-* wrapper, etc.)
    //   4. Map output to EtchWP block JSON
    //   5. If !$dry_run, persist via EtchWP API
    //   6. Update memory with new global classes
    //
    // Returning a stub so the wiring is verifiable end-to-end.

    $stub_payload = [
        'section' => $target === 'auto' ? 'hero' : $target,
        'global_classes_proposed' => $apply_globals ? ['btn-cta-pair', 'card-feature'] : [],
        'wireframe' => "section-l\n  container\n    grid 2col\n      column heading + sub + cta-pair\n      column image",
    ];

    return [
        'success' => true,
        'summary' => sprintf('Planned EtchWP %s section from image (dry_run=%s).', $stub_payload['section'], $dry_run ? 'true' : 'false'),
        'global_classes_proposed' => $stub_payload['global_classes_proposed'],
        'payload' => $stub_payload,
        'next_steps' => [
            'Review the wireframe',
            'Confirm color palette via memory key "color-palette"',
            $dry_run ? 'Re-run with dry_run=false to commit' : 'Refine spacing tokens',
        ],
    ];
}
