<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Elementor Pro — HTML / image / URL → native Elementor page.
 *
 * Conversion synthesis happens AGENT-SIDE (read SKILL.md + the live registry,
 * build a nested tree of containers + widgets). This ability is the guardrail +
 * writer: build → validate (against the live registry) → score → (dry_run)
 * report → persist atomically. Same gate model as kadence-pro / etchwp-pro: a
 * _preflight_token is required to persist.
 */

require_once __DIR__ . '/../lib/registry.php';
require_once __DIR__ . '/../lib/converter.php';
require_once __DIR__ . '/../lib/validator.php';
require_once __DIR__ . '/../lib/scorer.php';
require_once __DIR__ . '/../lib/persister.php';

function nibwp_elementor_pro_convert_schema(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'source' => [
                'type' => 'object',
                'description' => 'What the agent converted FROM (for recommendations). {html?, url?, notes?}',
                'properties' => ['html' => ['type' => 'string'], 'url' => ['type' => 'string'], 'notes' => ['type' => 'string']],
            ],
            'tree' => [
                'type' => 'array',
                'description' => 'The agent-built Elementor element tree. Each node: {elType:"container"|"widget", widgetType?, settings:{}, elements:[]}. Sections/columns → nested containers; content → real widgets. Use ONLY widgetTypes from nibwp/elementor-pro-list-widgets and control ids from nibwp/elementor-pro-widget-schema. ids are minted for you.',
                'items' => ['type' => 'object'],
            ],
            'target' => [
                'type' => 'object',
                'description' => 'Where to persist. mode: new_page | new_post | update. template: elementor_canvas | elementor_header_footer | default.',
                'properties' => [
                    'mode'     => ['type' => 'string', 'enum' => ['new_page', 'new_post', 'update']],
                    'post_id'  => ['type' => 'integer'],
                    'title'    => ['type' => 'string'],
                    'template' => ['type' => 'string'],
                ],
            ],
            'dry_run' => ['type' => 'boolean', 'default' => false, 'description' => 'Validate + score only; no write.'],
            '_preflight_token' => ['type' => 'string', 'description' => 'Token from nibwp/skill-preflight { skill_id:"elementor-pro" }. Required to persist.'],
        ],
        'additionalProperties' => false,
    ];
}

foreach ([
    'nibwp/elementor-pro-html-to-page'  => ['Elementor Pro — HTML to Elementor page', 'Convert pasted HTML into a validated, native Elementor page (flexbox containers + real widgets) and persist it as a page or post.'],
    'nibwp/elementor-pro-image-to-page' => ['Elementor Pro — image/screenshot to Elementor page', 'Rebuild a screenshot or design image as a native Elementor page. Extract structure/colors/type agent-side, then submit the tree here to validate + persist.'],
    'nibwp/elementor-pro-url-to-page'   => ['Elementor Pro — URL to Elementor page', 'Recreate a live web page as a native Elementor page. Fetch + rebuild agent-side, then submit the tree here to validate + persist.'],
] as $slug => [$label, $desc]) {
    wp_register_ability($slug, [
        'label'       => $label,
        'description' => $desc,
        'category'    => 'elementor-pro',
        'input_schema' => nibwp_elementor_pro_convert_schema(),
        'execute_callback'    => 'nibwp_elementor_pro_convert',
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true, 'type' => 'tool'],
            'annotations' => [
                'instructions' => implode("\n", [
                    'Convert to a native Elementor tree AGENT-SIDE, then submit it here.',
                    'Routine:',
                    '  1. nibwp/skill-preflight { skill_id:"elementor-pro" } — answer target mode, title, template. Mints _preflight_token.',
                    '  2. nibwp/load-skill-playbook { skill_id:"elementor-pro" } — read SKILL.md + references.',
                    '  3. nibwp/elementor-pro-list-widgets — the real widgetTypes on this site (Pro-aware).',
                    '  4. nibwp/elementor-pro-widget-schema { widget } — real control ids for each widget you will use.',
                    '  5. Build a nested tree: sections/columns = kind "container" (flex_direction row/column); content = widgets (heading, text-editor, image, button, icon-box…). Style with control ids; add tablet/mobile variants; images need an attachment id (sideload first).',
                    '  6. Submit with dry_run:true + _preflight_token → read validation + score. Fix every failed[] item.',
                    '  7. Re-submit dry_run:false to persist. Data is slashed + CSS regenerated, so the front end renders immediately.',
                    'Hard rules: real widgetTypes only; real control ids only; flexbox containers (not legacy section/column) for new layouts; unique ids (minted for you); no Pro-only widgets without Pro; sideload images to real attachment ids.',
                ]),
                'readonly' => false, 'destructive' => false, 'idempotent' => false,
            ],
        ],
    ]);
}

function nibwp_elementor_pro_convert(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('elementor-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }
    if (!nibwp_elementor_pro_active()) {
        return new WP_Error('elementor_missing', 'Elementor is not active on this site.');
    }

    $tree_in = (array) ($input['tree'] ?? []);
    if ($tree_in === []) {
        return new WP_Error('empty_tree', 'Provide a non-empty "tree" of Elementor elements.');
    }
    $dry_run   = !empty($input['dry_run']);
    $raw_token = (string) ($input['_preflight_token'] ?? '');

    // Preflight gate (required to persist; also keeps dry_run on-rail).
    if (!function_exists('nibwp_skill_preflight_consume_token')) {
        require_once __DIR__ . '/../../../abilities/skill-preflight.php';
    }
    $token_payload = nibwp_skill_preflight_consume_token($raw_token, 'elementor-pro', $input);
    if (is_wp_error($token_payload)) {
        return [
            'success' => false,
            'requires_user_input' => true,
            'question' => 'Run nibwp/skill-preflight { skill_id:"elementor-pro" } first to obtain a _preflight_token.',
            'next_action' => 'call_preflight',
            'summary' => 'Preflight gate: ' . $token_payload->get_error_message(),
        ];
    }

    // Build + validate + score.
    $tree       = nibwp_elementor_pro_build($tree_in);
    $validation = nibwp_elementor_pro_validate($tree);
    $score      = nibwp_elementor_pro_score($tree, $validation);

    if (!$validation['passed']) {
        nibwp_skill_preflight_bump_attempts($raw_token);
        return [
            'success' => false,
            'validation' => $validation,
            'unchecked_items' => $validation['failed'],
            'score' => $score,
            'summary' => sprintf('Validation failed: %d issue(s). Fix and resubmit.', count($validation['failed'])),
            'next_steps' => ['Fix each item in unchecked_items', 'Resubmit dry_run:true to confirm', 'Then dry_run:false to persist'],
        ];
    }

    // Ask Elementor what it does with this, rather than trusting that our model
    // of Elementor is right. The validator checks the tree against what we
    // believe Elementor wants; when that belief is wrong the two agree with each
    // other all the way to the page. Kadence shipped two bugs of exactly that
    // shape before this gate existed.
    $render = null;
    if (function_exists('nibwp_render_check')) {
        $expect = nibwp_elementor_pro_render_expectations($tree);
        if ($expect['expect_text'] !== [] || $expect['expect_markup'] !== []) {
            $render = nibwp_render_check([
                'builder' => 'elementor',
                'content' => '',
                'meta' => [
                    '_elementor_data' => wp_json_encode($tree),
                    '_elementor_edit_mode' => 'builder',
                    '_elementor_version' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.0.0',
                ],
            ] + $expect);
        }
    }

    if ($render !== null && empty($render['passed'])) {
        return [
            'success' => false,
            'error' => 'render_check_failed',
            'validation' => $validation,
            'score' => $score,
            'render_check' => $render,
            'summary' => nibwp_render_check_summary($render),
            'next_steps' => [
                'This saves and validates but does not render, so the page would look broken or empty.',
                'Check the widgetType and required settings named in render_check.failed against nibwp/elementor-pro-schema.',
                'A widget that renders nothing is usually missing a setting it reads before it draws anything.',
            ],
        ];
    }

    if ($dry_run) {
        return [
            'success' => true,
            'dry_run' => true,
            'validation' => $validation,
            'score' => $score,
            'render_check' => $render,
            'element_count' => $validation['element_count'],
            'summary' => sprintf('Validation passed (score %d, grade %s). %d warnings.%s Resubmit dry_run:false to persist.', $score['score'], $score['grade'], count($validation['warnings']), $render !== null ? ' The page renders correctly.' : ''),
        ];
    }

    // Persist — merge cached preflight answers into the target.
    $cached = (array) ($token_payload['answers'] ?? []);
    $target = (array) ($input['target'] ?? []);
    if (empty($target['mode']) && !empty($cached['elementor_push_mode'])) {
        $target['mode'] = (string) $cached['elementor_push_mode'];
    }
    if (empty($target['title']) && !empty($cached['elementor_new_title'])) {
        $target['title'] = (string) $cached['elementor_new_title'];
    }
    if (empty($target['post_id']) && !empty($cached['elementor_target_post_id'])) {
        $target['post_id'] = (int) $cached['elementor_target_post_id'];
    }
    if (empty($target['template']) && !empty($cached['elementor_template'])) {
        $target['template'] = (string) $cached['elementor_template'];
    }

    $result = nibwp_elementor_pro_persist($tree, $target);
    if (is_wp_error($result)) {
        nibwp_skill_preflight_bump_attempts($raw_token);
        return $result;
    }
    nibwp_skill_preflight_clear_token($raw_token);

    return [
        'success' => true,
        'validation' => $validation,
        'score' => $score,
        'result' => $result,
        'edit_url' => $result['edit_url'] ?? '',
        'view_url' => $result['view_url'] ?? '',
        'summary' => sprintf('Persisted %d native Elementor elements (mode=%s, score %s). Data slashed + CSS regenerated — the front end renders now.', $result['elements'] ?? 0, $result['mode'] ?? '', $score['grade']),
        'next_steps' => array_filter([
            'Open the edit_url in Elementor to fine-tune.',
            count($validation['warnings']) ? sprintf('%d warnings to review (responsive, alt text, control ids).', count($validation['warnings'])) : '',
            'Call nibwp/elementor-pro-feedback { rating, reason? }',
        ]),
    ];
}
