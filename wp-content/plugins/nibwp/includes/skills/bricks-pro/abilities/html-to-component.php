<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Bricks Pro — HTML → Bricks template / section.
 *
 * Agent-side conversion + server-side validation + persistence (delegated
 * to the existing `nibwp/bricks-create-template` ability). Same gate model
 * as etchwp-pro-html-to-component:
 *
 *   1. _preflight_token (required) — bound to user_id + skill_id + 3-attempt budget.
 *   2. Cached_answers OVERRIDE brand / template_type / push_mode / target_template_id.
 *   3. Validator hard-rejects unknown elements, inline styles, inline @media,
 *      hardcoded colors/font-sizes, missing brand prefix, raw form/iframe.
 *   4. Orchestrator-recommender emits cross-ability suggestions
 *      (loop→Query+CPT+ACF, iframe→video, raw form→bricks form/shortcode, …).
 *   5. dry_run true returns validation + recommendations; false persists.
 */

wp_register_ability('nibwp/bricks-pro-html-to-component', [
    'label'       => __('Bricks Pro — HTML / URL / image to Bricks template', 'nibwp'),
    'description' => __('Validates an agent-built Bricks payload against the Bricks Pro playbook (element whitelist, ACSS tokens, global classes, no inline @media, no raw form/iframe) and persists it as a Bricks template (header/footer/content/section/archive/error/popup). Conversion synthesis happens on the agent side; this ability is the guardrail + writer. Trigger words: "brickify", "convert to bricks", "html to bricks", "create bricks section".', 'nibwp'),
    'category'    => 'bricks-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'source' => [
                'type' => 'object',
                'description' => 'Original source the agent converted FROM (HTML / URL / image / Figma). Used for orchestration recommendations + form short-circuit.',
                'properties' => [
                    'html'  => ['type' => 'string'],
                    'url'   => ['type' => 'string'],
                    'notes' => ['type' => 'string'],
                ],
            ],
            'payload' => [
                'type' => 'object',
                'description' => 'Agent-built Bricks artifact: { template_type, global_classes, elements: [{name, settings, parent, children}, ...] }. The validator enforces every rule from SKILL.md and references/anti-patterns.md.',
            ],
            'target' => [
                'type' => 'object',
                'description' => 'Where to persist.',
                'properties' => [
                    'template_id'   => ['type' => 'integer', 'description' => 'Existing template post ID to update (only used when mode=replace_template or append_to_existing).'],
                    'mode'          => ['type' => 'string', 'enum' => ['new_template', 'replace_template', 'append_to_existing']],
                    'title'         => ['type' => 'string'],
                    'template_type' => ['type' => 'string', 'enum' => ['header', 'footer', 'content', 'section', 'archive', 'error', 'popup']],
                ],
            ],
            'brand' => [
                'type' => 'string',
                'description' => 'Brand slug for the BEM class prefix. Defaults to option nibwp_bricks_brand or "etched".',
            ],
            'element_type' => [
                'type' => 'string',
                'enum' => [
                    'button','hero','card-grid','form','navbar','footer',
                    'accordion','tabs','slider','image','divider','marquee',
                    'stat-block','testimonial','pricing','cta','section','header','archive',
                ],
                'description' => 'Which per-element checklist applies. Drives lessons-learned injection.',
            ],
            'checklist_results' => [
                'type' => 'array',
                'description' => 'Per-checklist-item self-report from the agent. Recorded as post meta for audit.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id'     => ['type' => 'string'],
                        'passed' => ['type' => 'boolean'],
                        'note'   => ['type' => 'string'],
                    ],
                ],
            ],
            'dry_run' => [
                'type'    => 'boolean',
                'default' => false,
                'description' => 'When true, run validation only; do not write to bricks_template / page meta.',
            ],
            '_preflight_token' => [
                'type'        => 'string',
                'description' => 'Token minted by nibwp/skill-preflight. REQUIRED. Server validates user_id/skill_id binding + expiry + attempts. Cached_answers override brand / template_type / target_template_id / push_mode.',
            ],
            '_form_decision' => [
                'type'        => 'object',
                'description' => 'Set after the form short-circuit returns choices. {plugin, form_id, shortcode_tag}.',
                'properties' => [
                    'plugin'         => ['type' => 'string'],
                    'form_id'        => ['type' => 'integer'],
                    'shortcode_tag'  => ['type' => 'string'],
                ],
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success'              => ['type' => 'boolean'],
            'requires_user_input'  => ['type' => 'boolean'],
            'question'             => ['type' => 'string'],
            'choices'              => ['type' => 'array'],
            'next_action'          => ['type' => 'string'],
            'validation'           => ['type' => 'object'],
            'unchecked_items'      => ['type' => 'array'],
            'diff'                 => ['type' => 'object'],
            'template_id'          => ['type' => 'integer'],
            'edit_url'             => ['type' => 'string'],
            'dry_run'              => ['type' => 'boolean'],
            'summary'              => ['type' => 'string'],
            'next_steps'           => ['type' => 'array', 'items' => ['type' => 'string']],
            'recommendations'      => [
                'type'        => 'array',
                'description' => 'Cross-ability orchestration suggestions: loop→Query Loop+CPT+ACF, iframe→Bricks video, raw form→nibwp/forms-manage+bricks form/shortcode, alt-less images, deep div-soup, dynamic-data binding for content/archive templates.',
                'items'       => ['type' => 'object'],
            ],
        ],
    ],
    'execute_callback'    => 'nibwp_bricks_pro_html_to_component',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => [
            'instructions' => "Convert HTML / URL / image / Figma to a Bricks template AGENT-SIDE, then submit the payload here.\n"
                . "Routine:\n"
                . "  1. nibwp/skill-preflight { skill_id:\"bricks-pro\" } — answer brand, template_type, push_mode, ACSS decision. Mints _preflight_token.\n"
                . "  2. nibwp/load-skill-playbook { skill_id:\"bricks-pro\", brand, element_type? } — read SKILL.md + element catalog + dynamic-data tags + per-element checklist + lessons-learned.\n"
                . "  3. Synthesize: flat array of Bricks elements {name, settings, parent_index, children_indices}. Use ONLY names in the catalog. Reference global classes via settings._cssGlobalClasses. Never inline @media. Never inline style=. Never raw <form>/<iframe>.\n"
                . "  4. Submit with dry_run:true + _preflight_token. Read response.recommendations[] — surface every recommendation (loop→CPT+ACF, iframe→video, form→bricks form, etc.) to the user with the choices. Run accepted ability chains if the user approves.\n"
                . "  5. Patch any unchecked_items (each carries copy-paste fix_hint). Re-submit until validation passes — 3-attempt budget per token.\n"
                . "  6. Re-submit with dry_run:false to commit. Delegates to nibwp/bricks-create-template.\n"
                . "  7. nibwp/bricks-pro-feedback { template_id, brand, element_type, rating, reason? }.\n"
                . "Hard rules:\n"
                . "  - Element names only from references/bricks-elements.md catalog\n"
                . "  - Tokens via var(--token, fallback) — never raw px/hex on color/font-size\n"
                . "  - Global classes BEM-prefixed: {brand}-{component}__{element}[--modifier]\n"
                . "  - Per-breakpoint via Bricks settings, NOT inline @media\n"
                . "  - Bricks-native video element instead of YouTube/Vimeo iframe\n"
                . "  - Bricks-native form element OR shortcode wrap (forms-manage) instead of raw <form>\n"
                . "  - Dynamic data tags ({post_title}, {acf:field}) for content/archive templates",
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function nibwp_bricks_pro_html_to_component(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('bricks-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }
    if (!defined('BRICKS_VERSION') && !class_exists('\\Bricks\\Templates') && !function_exists('bricks_render_dynamic_data')) {
        return new WP_Error('bricks_missing', 'Bricks plugin is not active.');
    }

    require_once __DIR__ . '/../lib/validator.php';
    require_once __DIR__ . '/../lib/orchestrator-recommender.php';

    $payload      = (array)  ($input['payload']       ?? []);
    $source       = (array)  ($input['source']        ?? []);
    $element_type = (string) ($input['element_type']  ?? '');
    $brand        = sanitize_key((string) ($input['brand'] ?? get_option('nibwp_bricks_brand', 'etched')));
    $dry_run      = !empty($input['dry_run']);
    $raw_token    = (string) ($input['_preflight_token'] ?? '');

    // Preflight gate.
    if (!function_exists('nibwp_skill_preflight_consume_token')) {
        require_once __DIR__ . '/../../../abilities/skill-preflight.php';
    }
    $token_payload = nibwp_skill_preflight_consume_token($raw_token, 'bricks-pro');
    if (is_wp_error($token_payload)) {
        return [
            'success'             => false,
            'requires_user_input' => true,
            'question'            => 'Run nibwp/skill-preflight { skill_id:"bricks-pro" } first to obtain a _preflight_token.',
            'next_action'         => 'call_preflight',
            'error_code'          => $token_payload->get_error_code(),
            'summary'             => sprintf('Preflight gate: %s', $token_payload->get_error_message()),
        ];
    }

    // Override agent-supplied values from cached_answers.
    $cached = (array) ($token_payload['answers'] ?? []);
    if (!empty($cached['brand_prefix'])) {
        $brand = sanitize_key((string) $cached['brand_prefix']);
    }
    $cached_target = [
        'template_id'       => isset($cached['target_template_id']) ? (int) $cached['target_template_id'] : 0,
        'mode'              => (string) ($cached['push_mode'] ?? ''),
        'template_type'     => (string) ($cached['template_type'] ?? ''),
        'new_template_title'=> (string) ($cached['new_template_title'] ?? ''),
    ];
    if ($cached_target['template_type'] !== '') {
        $payload['template_type'] = $cached_target['template_type'];
    }

    // Form short-circuit on source.html. Same flow as etchwp-pro.
    $source_html = (string) ($source['html'] ?? '');
    if ($source_html !== '' && stripos($source_html, '<form') !== false && empty($input['_form_decision'])) {
        // Reuse etchwp's form-detector for the installed-plugin list.
        require_once __DIR__ . '/../../etchwp-pro/lib/form-detector.php';
        $form_check = nibwp_etchwp_detect_form_in_source(['html' => $source_html]);
        return [
            'success'             => false,
            'requires_user_input' => true,
            'question'            => 'Detected a <form> in the source. Which installed form plugin should this be? Bricks offers a native form element too — if you prefer to keep the form rendering inside Bricks, set _form_decision.plugin = "__bricks_native_form__".',
            'choices'             => $form_check['installed_plugins'],
            'next_action'         => 'Ask the user which plugin (or "__bricks_native_form__") + form_id. Re-submit with _form_decision = { plugin, form_id, shortcode_tag } and a Bricks "form" or "shortcode" element in the payload.',
            'summary'             => 'Form detected; awaiting user choice of form plugin (or Bricks native form).',
        ];
    }

    // Validate.
    $validation = nibwp_bricks_pro_validate_payload($payload, [
        'element_type' => $element_type,
        'brand'        => $brand,
        'source_html'  => $source_html,
    ]);

    // Recommendations (computed once, attached to all response shapes).
    $recommendations = nibwp_bricks_pro_recommend_abilities($payload, [
        'source_html'    => $source_html,
        'element_type'   => $element_type,
        'brand'          => $brand,
    ]);

    if (!$validation['passed']) {
        nibwp_skill_preflight_bump_attempts($raw_token);
        return [
            'success'         => false,
            'validation'      => $validation,
            'unchecked_items' => $validation['failed'],
            'recommendations' => $recommendations,
            'attempts_used'   => (int) ($token_payload['attempts'] ?? 0) + 1,
            'attempts_max'    => defined('NIBWP_PREFLIGHT_MAX_ATTEMPTS') ? NIBWP_PREFLIGHT_MAX_ATTEMPTS : 3,
            'summary'         => sprintf(
                'Validation failed: %d unchecked item(s)%s. Patch and resubmit.',
                count($validation['failed']),
                $recommendations === [] ? '' : sprintf('; also %d orchestration suggestion(s) to surface to the user', count($recommendations))
            ),
            'next_steps' => array_filter([
                'Fix each item in unchecked_items (each carries a copy-paste fix_hint)',
                $recommendations !== [] ? 'Surface every recommendation to the user with its choices before re-submitting' : '',
                'Re-submit with dry_run:true to confirm a clean validator pass',
                'Then re-submit with dry_run:false to commit',
            ]),
        ];
    }

    if ($dry_run) {
        return [
            'success'         => true,
            'dry_run'         => true,
            'validation'      => $validation,
            'recommendations' => $recommendations,
            'summary'         => $recommendations === []
                ? 'Validation passed. Resubmit with dry_run:false to commit.'
                : sprintf('Validation passed BUT %d orchestration suggestion(s) — surface to the user first, run accepted ability chains, then re-submit with dry_run:false.', count($recommendations)),
            'next_steps' => array_filter([
                $recommendations !== [] ? 'Surface every recommendation' : '',
                'Resubmit with dry_run:false and a target.title (when mode=new_template) to persist.',
            ]),
        ];
    }

    // Persist via the existing bricks-create-template ability.
    $target        = (array) ($input['target'] ?? []);
    $mode          = $cached_target['mode'] !== '' ? $cached_target['mode'] : (string) ($target['mode'] ?? 'new_template');
    $template_type = (string) ($payload['template_type'] ?? $target['template_type'] ?? 'content');
    $title         = (string) ($target['title'] ?? $cached_target['new_template_title'] ?? sprintf('Bricks template %s', wp_date('Y-m-d H:i')));

    $persist_input = [
        'title'         => $title,
        'template_type' => $template_type,
        'elements'      => array_values((array) ($payload['elements'] ?? [])),
    ];
    if (in_array($mode, ['replace_template', 'append_to_existing'], true)) {
        $tid = $cached_target['template_id'] > 0 ? $cached_target['template_id'] : (int) ($target['template_id'] ?? 0);
        if ($tid > 0) {
            $persist_input['template_id'] = $tid;
        }
    }

    if (!function_exists('nibwp_bricks_create_template')) {
        require_once WP_PLUGIN_DIR . '/nibwp/includes/premium/integrations/bricks.php';
    }
    if (!function_exists('nibwp_bricks_create_template')) {
        return new WP_Error('bricks_create_unavailable', 'nibwp_bricks_create_template not loaded. Pro integration missing.');
    }

    $diff = nibwp_bricks_create_template($persist_input);
    if (is_wp_error($diff)) {
        nibwp_skill_preflight_bump_attempts($raw_token);
        return $diff;
    }

    // Persist global_classes via Bricks options if the payload provides them.
    if (!empty($payload['global_classes']) && is_array($payload['global_classes'])) {
        require_once __DIR__ . '/../lib/global-classes.php';
        nibwp_bricks_merge_global_classes($payload['global_classes']);
    }

    // Record checklist results as post meta.
    if (!empty($input['checklist_results']) && is_array($input['checklist_results'])) {
        update_post_meta(
            (int) $diff['template_id'],
            '_nibwp_bricks_pro_checklist',
            array_values($input['checklist_results']),
        );
    }

    nibwp_skill_preflight_clear_token($raw_token);

    return [
        'success'         => true,
        'template_id'     => (int) $diff['template_id'],
        'edit_url'        => (string) ($diff['edit_url'] ?? ''),
        'validation'      => $validation,
        'recommendations' => $recommendations,
        'summary'         => sprintf(
            'Persisted as Bricks %s template id=%d%s.',
            $template_type,
            (int) $diff['template_id'],
            $recommendations === [] ? '' : sprintf(', %d follow-up suggestion(s)', count($recommendations))
        ),
        'next_steps' => array_filter([
            $recommendations !== [] ? 'Surface each recommendation in recommendations[] to the user — accepted ones become a separate ability chain (CPT, ACF, Bricks video swap, etc.)' : '',
            'Open ' . ($diff['edit_url'] ?? '/wp-admin/edit.php?post_type=bricks_template') . ' to inspect',
            'Ask the user thumb-up or thumb-down',
            'Call nibwp/bricks-pro-feedback { template_id, brand, element_type, rating, reason? }',
        ]),
    ];
}
