<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Kadence Pro — HTML / image / URL → Kadence blocks.
 *
 * Conversion synthesis happens AGENT-SIDE (read SKILL.md + references, build a
 * nested tree of Kadence blocks). This ability is the guardrail + writer:
 * build → validate → score → (dry_run) report → persist. Same gate model as
 * bricks-pro / etchwp-pro: a _preflight_token is required to persist.
 */

require_once __DIR__ . '/../lib/converter.php';
require_once __DIR__ . '/../lib/validator.php';
require_once __DIR__ . '/../lib/scorer.php';
require_once __DIR__ . '/../lib/persister.php';

/** Shared input schema for the three source variants. */
function nibwp_kadence_pro_convert_schema(): array
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
                'description' => 'The agent-built Kadence block tree. Each node: {block:"kadence/rowlayout", attrs:{}, content?, level?, children:[]}. Use ONLY real Kadence block names (nibwp/kadence-pro-list-blocks). A section is kadence/rowlayout → kadence/column → content. Buttons are kadence/singlebtn inside kadence/advancedbtn.',
                'items' => ['type' => 'object'],
            ],
            'target' => [
                'type' => 'object',
                'description' => 'Where to persist. mode: new_page | new_post | update | element (Kadence header/footer/hook) | pattern (reusable).',
                'properties' => [
                    'mode'    => ['type' => 'string', 'enum' => ['new_page', 'new_post', 'update', 'element', 'pattern']],
                    'post_id' => ['type' => 'integer'],
                    'title'   => ['type' => 'string'],
                ],
            ],
            'dry_run' => ['type' => 'boolean', 'default' => false, 'description' => 'Validate + score only; no write.'],
            '_preflight_token' => ['type' => 'string', 'description' => 'Token from nibwp/skill-preflight { skill_id:"kadence-pro" }. Required to persist.'],
        ],
        'additionalProperties' => false,
    ];
}

foreach ([
    'nibwp/kadence-pro-html-to-blocks'  => ['Kadence Pro — HTML to Kadence blocks', 'Convert pasted HTML into a validated Kadence block layout (rowlayout/column/advancedheading/singlebtn/image/infobox…) and persist it as a page, post, Kadence element, or reusable pattern.'],
    'nibwp/kadence-pro-image-to-blocks' => ['Kadence Pro — image/screenshot to Kadence blocks', 'Rebuild a screenshot or design image as a Kadence block layout. Extract structure/colors/type agent-side, then submit the tree here to validate + persist.'],
    'nibwp/kadence-pro-url-to-blocks'   => ['Kadence Pro — URL to Kadence blocks', 'Recreate a live web page as a Kadence block layout. Fetch + rebuild agent-side, then submit the tree here to validate + persist.'],
] as $slug => [$label, $desc]) {
    wp_register_ability($slug, [
        'label'       => $label,
        'description' => $desc,
        'category'    => 'kadence-pro',
        'input_schema' => nibwp_kadence_pro_convert_schema(),
        'execute_callback'    => 'nibwp_kadence_pro_convert',
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true, 'type' => 'tool'],
            'annotations' => [
                'instructions' => implode("\n", [
                    'Convert to Kadence blocks AGENT-SIDE, then submit the tree here.',
                    'Routine:',
                    '  1. nibwp/skill-preflight { skill_id:"kadence-pro" } — answer brand, target mode, title. Mints _preflight_token.',
                    '  2. nibwp/load-skill-playbook { skill_id:"kadence-pro" } — read SKILL.md + references/kadence-blocks.md (block map, uniqueID rules, checklists).',
                    '  3. Build a nested tree: sections = kadence/rowlayout with "columns" + kadence/column children; headings = kadence/advancedheading {content, level}; buttons = kadence/singlebtn inside kadence/advancedbtn; features = kadence/infobox; body text = core/paragraph.',
                    '  4. Submit with dry_run:true + _preflight_token → read validation + score. Fix any failed[] items.',
                    '  5. Re-submit dry_run:false to persist.',
                    'Hard rules: real Kadence block names only; kadence/column only inside kadence/rowlayout; kadence/singlebtn only inside kadence/advancedbtn; every kadence/* block gets a uniqueID (minted for you); images need alt text.',
                ]),
                'readonly' => false, 'destructive' => false, 'idempotent' => true,
            ],
        ],
    ]);
}

function nibwp_kadence_pro_convert(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('kadence-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }
    if (!nibwp_kadence_pro_blocks_active()) {
        return new WP_Error('kadence_blocks_missing', 'Kadence Blocks is not active. Install/activate Kadence Blocks (free) to render Kadence blocks.');
    }

    $tree = (array) ($input['tree'] ?? []);
    if ($tree === []) {
        return new WP_Error('empty_tree', 'Provide a non-empty "tree" of Kadence blocks.');
    }
    $dry_run = !empty($input['dry_run']);
    $raw_token = (string) ($input['_preflight_token'] ?? '');

    // Preflight gate (required to persist; also required for dry_run to stay on-rail).
    if (!function_exists('nibwp_skill_preflight_consume_token')) {
        require_once __DIR__ . '/../../../abilities/skill-preflight.php';
    }
    $token_payload = nibwp_skill_preflight_consume_token($raw_token, 'kadence-pro');
    if (is_wp_error($token_payload)) {
        return [
            'success' => false,
            'requires_user_input' => true,
            'question' => 'Run nibwp/skill-preflight { skill_id:"kadence-pro" } first to obtain a _preflight_token.',
            'next_action' => 'call_preflight',
            'summary' => 'Preflight gate: ' . $token_payload->get_error_message(),
        ];
    }

    // Build + validate + score.
    $blocks = nibwp_kadence_build_blocks($tree);
    $validation = nibwp_kadence_validate_blocks($blocks);
    $score = nibwp_kadence_score($blocks, $validation);

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

    // Render before writing anywhere real.
    //
    // The validator checks this markup against what we believe Kadence wants,
    // so when the belief is wrong the validator is wrong the same way and the
    // two agree all the way to production. That is not hypothetical: a row
    // missing kbVersion and an image written attribute-only both passed every
    // check here and rendered nothing. Rendering asks Kadence instead of asking
    // ourselves, which is the only question whose answer we do not already
    // think we know.
    $render = null;
    if (function_exists('nibwp_render_check') && function_exists('nibwp_kadence_render_expectations')) {
        $expect = nibwp_kadence_render_expectations($blocks);
        if ($expect['expect_text'] !== [] || $expect['expect_markup'] !== []) {
            $render = nibwp_render_check([
                'builder' => 'blocks',
                'content' => implode("\n\n", array_map('nibwp_kadence_serialize_block', $blocks)),
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
                'This markup saves and validates but does not render correctly, so it would look broken on the page.',
                'Check the attributes named in render_check.failed against references/kadence-blocks.md.',
                'Do not work around it by writing raw HTML: fix the attribute the block actually reads.',
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
            'block_count' => $validation['block_count'],
            'summary' => sprintf(
                'Validation passed (score %d/%s)%s. Resubmit dry_run:false to persist.',
                $score['score'],
                $score['grade'],
                $render !== null ? ' and the page renders correctly' : ''
            ),
        ];
    }

    // Persist.
    $cached = (array) ($token_payload['answers'] ?? []);
    $target = (array) ($input['target'] ?? []);
    if (!empty($cached['kadence_push_mode']) && empty($target['mode'])) {
        $target['mode'] = (string) $cached['kadence_push_mode'];
    }
    if (!empty($cached['kadence_new_title']) && empty($target['title'])) {
        $target['title'] = (string) $cached['kadence_new_title'];
    }

    $result = nibwp_kadence_persist($blocks, $target);
    if (is_wp_error($result)) {
        nibwp_skill_preflight_bump_attempts($raw_token);
        return $result;
    }
    nibwp_skill_preflight_clear_token($raw_token);

    // Which blocks in this tree keep their text in markup rather than in
    // attributes. Kadence's save() builds that markup in JavaScript from a
    // dozen attributes, and the editor compares what it finds against what
    // that function would have produced — so markup written from PHP is
    // flagged as invalid however carefully it is built. Naming the blocks it
    // applies to is more use than a general warning.
    $carriers = nibwp_kadence_pro_source_html_blocks($blocks);

    return [
        'success' => true,
        'validation' => $validation,
        'score' => $score,
        'render_check' => $render,
        'result' => $result,
        'edit_url' => $result['edit_url'] ?? '',
        'needs_editor_save' => $carriers !== [],
        'summary' => sprintf(
            'Persisted %d native Kadence blocks (mode=%s, score %s). Design is in attributes, so the front end renders now.%s',
            $result['blocks'] ?? 0,
            $result['mode'] ?? '',
            $score['grade'],
            $carriers !== []
                ? sprintf(' Open the page in the editor once and save: %s keep their text in markup, and the editor rewrites that markup itself.', implode(', ', $carriers))
                : ''
        ),
        'next_steps' => array_filter([
            'Verify on the front end — it renders now.',
            $carriers !== []
                ? sprintf(
                    'Tell the user to open the page in the block editor and save it once. %s store text as markup, and only the editor can write that markup the way Kadence expects — until then those blocks offer "Attempt Block Recovery". Design lives in attributes, so the save keeps every style.',
                    implode(', ', $carriers)
                )
                : '',
            $result['note'] ?? '',
            'Call nibwp/kadence-pro-feedback { rating, reason? }',
        ]),
    ];
}

/**
 * Block names in this tree whose text Kadence stores as markup.
 *
 * These are the ones the editor will offer to recover, because their saved
 * markup has to match a JavaScript save() that PHP cannot reproduce. Everything
 * else is attributes-only and survives untouched.
 *
 * @param array<int,mixed> $blocks
 * @return array<int,string>
 */
function nibwp_kadence_pro_source_html_blocks(array $blocks): array
{
    $carriers = array_keys(nibwp_kadence_source_html_fields());
    $found = [];

    $walk = static function ($nodes) use (&$walk, $carriers, &$found): void {
        foreach ((array) $nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $name = (string) ($node['blockName'] ?? $node['name'] ?? '');
            if ($name !== '' && in_array($name, $carriers, true)) {
                $found[$name] = true;
            }
            if (!empty($node['innerBlocks'])) {
                $walk($node['innerBlocks']);
            }
        }
    };
    $walk($blocks);

    return array_keys($found);
}

/** Is Kadence Blocks active? (skill-scoped name; the kadence integration defines its own nibwp_kadence_blocks_active). */
function nibwp_kadence_pro_blocks_active(): bool
{
    return defined('KADENCE_BLOCKS_VERSION')
        || class_exists('Kadence_Blocks_Frontend')
        || function_exists('kadence_blocks_get_current_template_id');
}

// ---------------------------------------------------------------------------
// Utility: preview (no token) — build + validate + score a tree without writing.
// ---------------------------------------------------------------------------
wp_register_ability('nibwp/kadence-pro-preview', [
    'label'       => __('Kadence Pro — preview / validate a block tree', 'nibwp'),
    'description' => __('Build + validate + score an agent-built Kadence block tree without persisting or a preflight token. Use to check structure before the real run.', 'nibwp'),
    'category'    => 'kadence-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['tree' => ['type' => 'array', 'items' => ['type' => 'object']]],
        'required' => ['tree'],
        'additionalProperties' => false,
    ],
    'execute_callback'    => 'nibwp_kadence_pro_preview',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function nibwp_kadence_pro_preview(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('kadence-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }
    $tree = (array) ($input['tree'] ?? []);
    if ($tree === []) {
        return new WP_Error('empty_tree', 'Provide a non-empty "tree".');
    }
    $blocks = nibwp_kadence_build_blocks($tree);
    $validation = nibwp_kadence_validate_blocks($blocks);
    return [
        'validation' => $validation,
        'score' => nibwp_kadence_score($blocks, $validation),
        'block_count' => $validation['block_count'],
    ];
}
