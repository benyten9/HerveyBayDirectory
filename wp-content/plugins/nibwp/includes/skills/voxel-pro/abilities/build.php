<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Voxel Pro — build a template.
 *
 * The document is composed agent-side from SKILL.md and the catalog. This
 * ability checks it against the live site — widget names, filter and field
 * keys, every dynamic tag rendered against a real listing — then writes it,
 * wires the search/feed/map relations itself, and assigns it if asked.
 */

require_once __DIR__ . '/../lib/registry.php';
require_once __DIR__ . '/../lib/relations.php';
require_once __DIR__ . '/../lib/validator.php';
require_once __DIR__ . '/../lib/scorer.php';
require_once __DIR__ . '/../lib/persister.php';

wp_register_ability('nibwp/voxel-pro-build', [
    'label'       => __('Voxel Pro — build a Voxel template', 'nibwp'),
    'description' => __('Validate and write a Voxel template: preview card, single post, archive, term, header, footer, search page, or style kit. Wires search forms to feeds and maps automatically, renders every dynamic tag against a real listing before writing, and can assign the result to a post type slot or a site slot.', 'nibwp'),
    'category'    => 'voxel-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'template' => [
                'type' => 'object',
                'description' => 'What is being built. kind decides the document type; post_type is required for card, single-post and archive. Pass existing_id to rebuild an existing template instead of creating one.',
                'properties' => [
                    'kind'        => ['type' => 'string', 'enum' => ['card', 'single-post', 'archive', 'single-term', 'header', 'footer', 'search-page', 'style-kit-popup', 'style-kit-timeline', 'page']],
                    'title'       => ['type' => 'string'],
                    'post_type'   => ['type' => 'string'],
                    'existing_id' => ['type' => 'integer'],
                ],
                'required' => ['kind'],
            ],
            'elements' => [
                'type' => 'array',
                'description' => 'The Elementor element tree. Each node: {id?, elType:"container"|"widget"|"section"|"column", widgetType?, settings:{}, elements:[]}. Element ids are minted for you if you leave them out. Voxel widgets are the ts-* types from nibwp/voxel-pro-catalog; ordinary Elementor widgets work too. Bind data with @tags()…@endtags() strings in any setting.',
                'items' => ['type' => 'object'],
            ],
            'relations' => [
                'description' => 'How search forms connect to feeds and maps. "auto" (default) pairs them by position — right for almost every layout. Pass {feedToSearch:[{left,right}], mapToSearch:[…]} with element ids to be explicit. Paths are always computed here; never send them.',
            ],
            'page_settings'   => ['type' => 'object', 'description' => 'Extra Elementor page settings to merge, e.g. a container width or the preview post for a card.'],
            'preview_post_id' => ['type' => 'integer', 'description' => 'Which listing the card previews with in the editor. Defaults to the newest published post of its post type.'],
            'assign' => [
                'type' => 'object',
                'description' => 'Where to put the finished template. Either {slot} for a site slot, or {post_type, pt_slot} for a post type\'s own. mode:"custom" adds a named alternate rather than replacing the main one.',
                'properties' => [
                    'slot'             => ['type' => 'string'],
                    'post_type'        => ['type' => 'string'],
                    'pt_slot'          => ['type' => 'string', 'enum' => ['single', 'card', 'archive', 'form']],
                    'taxonomy'         => ['type' => 'string'],
                    'tax_slot'         => ['type' => 'string', 'enum' => ['single', 'card']],
                    'mode'             => ['type' => 'string', 'enum' => ['main', 'custom']],
                    'label'            => ['type' => 'string'],
                    'visibility_rules' => ['type' => 'array'],
                    'confirm_kit'      => ['type' => 'boolean', 'description' => 'Required to assign kit_popups or kit_timeline — both restyle the whole site.'],
                ],
            ],
            'dry_run' => ['type' => 'boolean', 'default' => false, 'description' => 'Validate and score only; write nothing.'],
            '_preflight_token' => ['type' => 'string', 'description' => 'Token from nibwp/skill-preflight { skill_id:"voxel-pro" }.'],
        ],
        'required' => ['template', 'elements'],
        'additionalProperties' => false,
    ],
    'execute_callback'    => 'nibwp_voxel_pro_build_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => [
            'instructions' => implode("\n", [
                'Compose the document agent-side, then submit it here.',
                'Routine:',
                '  1. nibwp/voxel-info — read this site\'s post type keys and modules.',
                '  2. nibwp/skill-preflight { skill_id:"voxel-pro" } — mints the _preflight_token.',
                '  3. nibwp/load-skill-playbook { skill_id:"voxel-pro", element_type:"<kind>" } — read SKILL.md and the checklist for what you are building.',
                '  4. nibwp/voxel-pro-catalog — confirm widget names, required settings, and the post type\'s filter and field keys.',
                '  5. Submit with dry_run:true → fix every failed[] item, read the warnings.',
                '  6. Re-submit dry_run:false to write it.',
                'Rules: widget types must exist on this site; filter, field and post type keys must be real; every @tags() value is rendered against a real listing and an unresolved one fails the build; relation paths are computed here, so never send them.',
            ]),
            'readonly' => false, 'destructive' => false, 'idempotent' => false,
        ],
    ],
]);

function nibwp_voxel_pro_build_execute(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('voxel-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }
    $guard = nibwp_voxel_pro_guard();
    if (is_wp_error($guard)) {
        return $guard;
    }

    $template  = (array) ($input['template'] ?? []);
    $kind      = (string) ($template['kind'] ?? '');
    $kinds     = nibwp_voxel_pro_kinds();
    if (!isset($kinds[$kind])) {
        return new WP_Error('unknown_kind', sprintf('Unknown template kind "%s". Choose one of: %s.', $kind, implode(', ', array_keys($kinds))));
    }

    $elements = (array) ($input['elements'] ?? []);
    if ($elements === []) {
        return new WP_Error('empty_document', 'Provide a non-empty "elements" tree. An empty template is never what was wanted.');
    }

    $dry_run   = !empty($input['dry_run']);
    $raw_token = (string) ($input['_preflight_token'] ?? '');

    if (!function_exists('nibwp_skill_preflight_consume_token')) {
        require_once __DIR__ . '/../../../abilities/skill-preflight.php';
    }
    $token_payload = nibwp_skill_preflight_consume_token($raw_token, 'voxel-pro', $input);
    if (is_wp_error($token_payload)) {
        return [
            'success' => false,
            'requires_user_input' => true,
            'question' => 'Run nibwp/skill-preflight { skill_id:"voxel-pro" } first to obtain a _preflight_token.',
            'next_action' => 'call_preflight',
            'summary' => 'Preflight gate: ' . $token_payload->get_error_message(),
        ];
    }

    // Fill gaps from the preflight answers rather than making the agent repeat itself.
    $cached = (array) ($token_payload['answers'] ?? []);
    if (($template['post_type'] ?? '') === '' && !empty($cached['voxel_post_type'])) {
        $template['post_type'] = (string) $cached['voxel_post_type'];
    }
    if (($template['title'] ?? '') === '' && !empty($cached['voxel_new_title'])) {
        $template['title'] = (string) $cached['voxel_new_title'];
    }
    if (empty($template['existing_id']) && !empty($cached['voxel_target_template_id'])) {
        $template['existing_id'] = (int) $cached['voxel_target_template_id'];
    }

    // Mint ids, then validate against the live site.
    $renamed  = [];
    $elements = nibwp_voxel_pro_normalize_tree($elements, $renamed);

    // An id that had to be replaced has to be replaced in the relations too,
    // or an explicit pairing would point at an element that no longer exists.
    $spec = $input['relations'] ?? 'auto';
    if (is_array($spec) && $renamed !== []) {
        foreach ($spec as $group => $pairs) {
            foreach ((array) $pairs as $i => $pair) {
                foreach (['left', 'right'] as $side) {
                    $was = (string) ($pair[$side] ?? '');
                    if ($was !== '' && isset($renamed[$was])) {
                        $spec[$group][$i][$side] = $renamed[$was];
                    }
                }
            }
        }
    }
    $relations  = nibwp_voxel_pro_resolve_relations($elements, $spec);
    $validation = nibwp_voxel_pro_validate($elements, $template, $relations, (array) ($input['assign'] ?? []));
    $score      = nibwp_voxel_pro_score($elements, $validation, $kind);

    if (!$validation['passed']) {
        nibwp_skill_preflight_bump_attempts($raw_token);
        return [
            'success'         => false,
            'validation'      => $validation,
            'unchecked_items' => $validation['failed'],
            'score'           => $score,
            'summary'         => sprintf('Validation failed: %d issue(s). Nothing was written.', count($validation['failed'])),
            'next_steps'      => ['Fix each item in unchecked_items', 'Resubmit dry_run:true to confirm', 'Then dry_run:false to write it'],
        ];
    }

    if ($dry_run) {
        return [
            'success'    => true,
            'dry_run'    => true,
            'validation' => $validation,
            'score'      => $score,
            'relations'  => $relations,
            'renamed_ids' => $renamed,
            'summary'    => sprintf(
                'Validation passed (%s, %d elements). Resubmit dry_run:false to write it.%s',
                $score['grade'],
                $validation['element_count'],
                $relations['feedToSearch'] === [] && $relations['mapToSearch'] === [] ? '' : sprintf(' %d search connection(s) will be wired.', count($relations['feedToSearch']) + count($relations['mapToSearch']))
            ),
        ];
    }

    $result = nibwp_voxel_pro_persist($elements, $template, $relations, [
        'page_settings'   => (array) ($input['page_settings'] ?? []),
        'preview_post_id' => (int) ($input['preview_post_id'] ?? 0),
        'assign'          => (array) ($input['assign'] ?? []),
    ]);
    if (is_wp_error($result)) {
        nibwp_skill_preflight_bump_attempts($raw_token);
        return $result;
    }
    nibwp_skill_preflight_clear_token($raw_token);

    return [
        'success'     => true,
        'validation'  => $validation,
        'score'       => $score,
        'result'      => $result,
        'renamed_ids' => $renamed,
        'summary'     => nibwp_voxel_pro_build_summary($result, $validation, $score)
            . ($renamed === [] ? '' : sprintf(' %d element id(s) were not valid Elementor ids and were replaced — see renamed_ids before refining.', count($renamed))),
        'next_steps' => array_values(array_filter([
            'Open the edit link and check it renders the way the user expects.',
            empty($result['assigned']) ? 'Nothing was assigned — the template exists but no slot points at it yet. Assign it with the assign block or nibwp/voxel-templates.' : '',
            !empty($result['backup_taken']) ? 'The previous version of this template is kept and can be restored with nibwp/voxel-pro-refine { restore_backup: true }.' : '',
            'Call nibwp/voxel-pro-feedback { rating, kind, reason? }',
        ])),
    ];
}

function nibwp_voxel_pro_build_summary(array $result, array $validation, array $score): string
{
    $parts = [sprintf(
        '%s "%s" (#%d) written with %d elements, %s.',
        $result['created'] ? 'Created' : 'Rebuilt',
        $result['title'],
        $result['template_id'],
        $validation['element_count'],
        $score['grade']
    )];
    if (!empty($result['relations_written'])) {
        $parts[] = sprintf('%d search connection(s) wired.', (int) $result['relations_written']);
    }
    if (!empty($result['assigned'])) {
        $parts[] = (string) $result['assigned']['summary'];
    }
    if ($validation['warnings'] !== []) {
        $parts[] = sprintf('%d warning(s) worth reading.', count($validation['warnings']));
    }
    return implode(' ', $parts);
}
