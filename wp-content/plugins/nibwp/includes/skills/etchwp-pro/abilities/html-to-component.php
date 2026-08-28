<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * EtchWP Pro — HTML/CSS → component.
 *
 * As of v1.3 the conversion synthesis runs AGENT-SIDE (Claude in the chat).
 * This ability is the server-side validator + persister:
 *
 *   1. Accept the agent-built `payload` (gutenbergBlock tree + styles dict + __libraryMeta).
 *   2. Detect <form> in source. If found, return a question to the agent
 *      ("which installed form plugin?") so the agent can ask the user, resubmit
 *      with payload._form_decision, and ensure an etch/shortcode block appears.
 *   3. Run the playbook validator (lib/validator.php). Failures are returned as
 *      structured unchecked_items so the agent patches and re-submits.
 *   4. On dry_run=true, return validation only.
 *   5. On dry_run=false, persist via lib/persister.php:
 *        - merge styles into wp_options['etch_styles']
 *        - append/replace the block markup inside the target post_content
 *      Returns { component_id, diff }.
 *   6. Record the agent-supplied checklist_results as post meta.
 *
 * Loaded only when the EtchWP Pro Skill is unlocked AND the host EtchWP plugin
 * is active AND the skill is toggled on in the marketplace.
 */

wp_register_ability('nibwp/etchwp-pro-html-to-component', [
    'label'       => __('EtchWP Pro — HTML / Website to Component', 'nibwp'),
    'description' => __('Validates an agent-built Etch payload against the EtchWP Pro playbook (BEM, ACSS tokens, no clamp() font-size, style-hoist, form shortcode) and persists it into etch_styles + the target post. Conversion synthesis happens on the agent side; this ability is the guardrail + writer. Trigger words: "convert to etch", "etchify", "etch this", "make this an etch section".', 'nibwp'),
    'category'    => 'etchwp-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'source' => [
                'type' => 'object',
                'description' => 'Original source the agent converted FROM. Used for form detection only — not for re-conversion.',
                'properties' => [
                    'html'  => ['type' => 'string'],
                    'url'   => ['type' => 'string'],
                    'notes' => ['type' => 'string'],
                ],
            ],
            'payload' => [
                'type' => 'object',
                'description' => 'Agent-built Etch artifact: { __libraryMeta, styles, gutenbergBlock, components? }. The validator enforces every rule from SKILL.md and anti-patterns.md.',
            ],
            'target' => [
                'type' => 'object',
                'description' => 'Where to persist.',
                'properties' => [
                    'post_id'        => ['type' => 'integer'],
                    'section_anchor' => ['type' => 'string'],
                    'mode'           => ['type' => 'string', 'enum' => ['append', 'replace_section', 'new_page']],
                ],
            ],
            'brand' => [
                'type' => 'string',
                'description' => 'Brand slug for the BEM class prefix. Defaults to option nibwp_etchwp_brand or "etched".',
            ],
            'element_type' => [
                'type' => 'string',
                'enum' => [
                    'button','hero','card-grid','form','navbar','footer',
                    'accordion','tabs','slider','image','divider','marquee',
                    'stat-block','testimonial','pricing','cta',
                ],
                'description' => 'Which per-element checklist applies. Drives the lessons-learned injection.',
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
                'description' => 'When true, run validation only; do not write to etch_styles or post_content.',
            ],
            '_preflight_token' => [
                'type'        => 'string',
                'description' => 'Token minted by nibwp/skill-preflight. REQUIRED. Server validates user_id binding + skill_id binding + expiry + attempt count, then OVERRIDES brand/target.post_id/target.mode from cached_answers. Calling this ability without a valid token returns requires_user_input:true with next_action:"call_preflight".',
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
            'component_id'         => ['type' => 'string'],
            'dry_run'              => ['type' => 'boolean'],
            'summary'              => ['type' => 'string'],
            'next_steps'           => ['type' => 'array', 'items' => ['type' => 'string']],
            'recommendations'      => [
                'type'        => 'array',
                'description' => 'Cross-ability orchestration suggestions: detected loops to dynamise via CPT+ACF, raw iframes to swap for etch/embed, missing alt text, raw <form> markup to route through forms-manage, deep div-soup to upgrade with semantic landmarks. The agent SHOULD surface each to the user with the listed choices before committing or as a follow-up after committing.',
                'items'       => ['type' => 'object'],
            ],
        ],
    ],
    'execute_callback'    => 'nibwp_etchwp_pro_html_to_component',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => [
            'instructions' => "Convert HTML / image / URL / Figma to an Etch component AGENT-SIDE, then submit the payload here for validation + persistence.\n"
                . "Routine:\n"
                . "  1. Call nibwp/load-skill-playbook { skill_id:\"etchwp-pro\", brand, element_type? } to read SKILL.md + per-element checklist + lessons-learned.\n"
                . "  2. Synthesize the payload (gutenbergBlock tree + styles dict + __libraryMeta) following the checklist.\n"
                . "  3. Submit with dry_run:true. Patch any unchecked_items, re-submit until validation.passed.\n"
                . "  4. Read the response's `recommendations[]` — these are cross-ability suggestions (loop → CPT+ACF, iframe → etch/embed, raw <form> → forms-manage, alt-less images, deep div-soup). Surface each to the USER with the listed choices BEFORE committing. Run the suggested ability chain if the user accepts.\n"
                . "  5. Re-submit with dry_run:false and a target post_id to commit.\n"
                . "  6. Ask the user thumb-up/down; call nibwp/etchwp-pro-feedback with the rating.\n"
                . "Hard rules enforced server-side:\n"
                . "  - NEVER clamp() font-size (anti-patterns.md §13)\n"
                . "  - Tokens from canonical taxonomy only (anti-patterns.md §14)\n"
                . "  - BEM grammar: {brand}-{component}__{element}[--modifier]\n"
                . "  - Every wp:html block needs a style-hoist wp:etch/element (anti-patterns.md §15)\n"
                . "  - Forms → etch/shortcode via nibwp/forms-manage (anti-patterns.md §16)\n"
                . "  - No raw <style> tag, no external stylesheet, no hardcoded font-size or color (validator hard rejects)\n"
                . "Expert routines (recommendations[]):\n"
                . "  - LOOP detected (≥3 repeating cards) → propose CPT + ACF + etch/loop-block. NEVER persist 6 identical static cards if the user accepts dynamic.\n"
                . "  - IFRAME (YouTube/Vimeo) → propose etch/embed block, not raw iframe.\n"
                . "  - <img> missing alt → propose nibwp/wp-upload-media with a generated alt OR mark decorative.\n"
                . "  - DIV-SOUP (6+ nested divs) → propose semantic landmark upgrade.\n"
                . "  - GALLERY (4+ sibling images) → propose ACF gallery field on a CPT.",
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function nibwp_etchwp_pro_html_to_component(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('etchwp-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }

    if (!defined('ETCH_PLUGIN_FILE')) {
        return new WP_Error('etchwp_missing', 'EtchWP plugin is not active.');
    }

    require_once __DIR__ . '/../lib/validator.php';
    require_once __DIR__ . '/../lib/persister.php';
    require_once __DIR__ . '/../lib/form-detector.php';
    require_once __DIR__ . '/../lib/orchestrator-recommender.php';

    $payload      = (array)  ($input['payload']       ?? []);
    $source       = (array)  ($input['source']        ?? []);
    $element_type = (string) ($input['element_type']  ?? '');
    $brand        = sanitize_key((string) ($input['brand'] ?? get_option('nibwp_etchwp_brand', 'etched')));
    $dry_run      = !empty($input['dry_run']);
    $raw_token    = (string) ($input['_preflight_token'] ?? '');

    // ── Preflight gate (INVARIANT 2 — no improvisation past this point) ──
    // Validate token: bound to current user_id, bound to "etchwp-pro" skill,
    // unexpired, has attempts remaining. Returns WP_Error on any failure.
    if (!function_exists('nibwp_skill_preflight_consume_token')) {
        require_once __DIR__ . '/../../../abilities/skill-preflight.php';
    }
    $token_payload = nibwp_skill_preflight_consume_token($raw_token, 'etchwp-pro');
    if (is_wp_error($token_payload)) {
        $err_code = $token_payload->get_error_code();
        return [
            'success'             => false,
            'requires_user_input' => true,
            'question'            => 'Run nibwp/skill-preflight first to obtain a _preflight_token. The conversion ability cannot bypass the preflight gate.',
            'next_action'         => 'call_preflight',
            'error_code'          => $err_code,
            'summary'             => sprintf('Preflight gate: %s', $token_payload->get_error_message()),
        ];
    }

    // ── Override agent-supplied values from cached_answers (INVARIANT 3) ──
    $cached = (array) ($token_payload['answers'] ?? []);
    if (!empty($cached['brand_prefix'])) {
        $brand = sanitize_key((string) $cached['brand_prefix']);
    }
    // Only a scalar, numeric answer is a real post id. A cached array (e.g. a
    // detected list of candidate posts) casts to int 1 in PHP, which silently
    // retargets the write at post 1 — never let that reach the persister.
    $cached_pid = $cached['target_post_id'] ?? null;
    $cached_target = [
        'post_id' => (is_scalar($cached_pid) && is_numeric($cached_pid)) ? (int) $cached_pid : 0,
        'mode'    => isset($cached['push_mode']) && is_scalar($cached['push_mode']) ? (string) $cached['push_mode'] : '',
        'new_page_title' => (string) ($cached['new_page_title'] ?? ''),
        'new_page_type'  => 'page',
    ];

    // 1) Form short-circuit — ask user every time.
    $form_check = nibwp_etchwp_detect_form_in_source($source);
    if ($form_check['has_form'] && empty($payload['_form_decision'])) {
        return [
            'success'             => false,
            'requires_user_input' => true,
            'question'            => 'Detected a <form> in the source. Which installed form plugin should this be?',
            'choices'             => $form_check['installed_plugins'],
            'next_action'         => 'Ask the user which installed plugin + form_id to use. Then re-submit with payload._form_decision = { plugin, form_id, shortcode_tag } and a gutenbergBlock containing an etch/shortcode block + style-hoist hidden block.',
            'summary'             => 'Form detected; awaiting user choice of installed form plugin.',
        ];
    }

    // 2) Validate against the full playbook.
    $validation = nibwp_etchwp_validate_payload($payload, [
        'element_type'       => $element_type,
        'brand'              => $brand,
        'has_raw_html_block' => $form_check['has_form'] || nibwp_etchwp_payload_has_wp_html($payload),
        'source_html'        => (string) ($source['html'] ?? ''),
    ]);

    // 2b) Cross-ability recommendations — computed once, attached to every
    // response shape so the agent surfaces them to the user regardless of
    // whether the payload passed validation.
    $recommendations = nibwp_etchwp_recommend_abilities($payload, [
        'source_html'  => (string) ($source['html'] ?? ''),
        'element_type' => $element_type,
        'brand'        => $brand,
    ]);

    if (!$validation['passed']) {
        // INVARIANT 7 — bounded retries. Bump the token's attempt counter
        // so the agent cannot loop indefinitely on the same token. After
        // NIBWP_PREFLIGHT_MAX_ATTEMPTS failures, the next consume_token
        // call returns attempts_exhausted and the agent has to escalate
        // to the user.
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
            'next_steps'      => array_filter([
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
                : sprintf('Validation passed BUT %d orchestration suggestion(s) — surface to the user first, run any accepted ability chain, then re-submit with dry_run:false.', count($recommendations)),
            'next_steps'      => array_filter([
                $recommendations !== [] ? 'Surface every recommendation to the user with its choices' : '',
                $recommendations !== [] ? 'Run accepted suggested_chain(s) (e.g. nibwp/wp-register-cpt → nibwp/acf-manage-fields), then resubmit' : '',
                'Resubmit with dry_run:false and a target.post_id to persist.',
            ]),
        ];
    }

    // 3) Persist — target overridden from cached_answers (INVARIANT 3).
    $target = (array) ($input['target'] ?? []);
    if ($cached_target['post_id'] > 0) {
        $target['post_id'] = $cached_target['post_id'];
    }
    if ($cached_target['mode'] !== '') {
        $target['mode'] = $cached_target['mode'];
    }
    if (($target['mode'] ?? '') === 'new_page' && $cached_target['new_page_title'] !== '') {
        $target['new_page_title'] = $cached_target['new_page_title'];
        $target['new_page_type']  = $cached_target['new_page_type'];
    }
    $diff = nibwp_etchwp_persist_payload($payload, $target);
    if (is_wp_error($diff)) {
        return $diff;
    }
    // INVARIANT 7 — one-shot semantics: clear the token after a successful
    // destructive persist so it can't be replayed. dry_run keeps it alive.
    nibwp_skill_preflight_clear_token($raw_token);

    // 4) Record the agent-supplied checklist results for audit.
    if (!empty($input['checklist_results']) && is_array($input['checklist_results'])) {
        update_post_meta(
            $diff['post_id'],
            '_nibwp_etchwp_checklist_' . $diff['component_id'],
            array_values($input['checklist_results']),
        );
    }

    return [
        'success'         => true,
        'component_id'    => $diff['component_id'],
        'validation'      => $validation,
        'diff'            => $diff,
        'recommendations' => $recommendations,
        'summary'         => sprintf(
            'Persisted to post %d. %d style(s) added, %d updated%s.',
            $diff['post_id'],
            count($diff['styles_added']),
            count($diff['styles_updated']),
            $recommendations === [] ? '' : sprintf(', %d follow-up orchestration suggestion(s)', count($recommendations))
        ),
        'next_steps' => array_filter([
            $recommendations !== [] ? 'Surface each recommendation in recommendations[] to the user — accepted ones become a separate ability chain (CPT, ACF, embed, etc.)' : '',
            'Ask the user thumb-up or thumb-down',
            'Call nibwp/etchwp-pro-feedback { component_id, brand, element_type, rating, reason? } with the rating',
        ]),
    ];
}

/**
 * Lowercase, hyphen-separated slug suitable for filenames + BEM blocks.
 * Kept here for back-compat with any earlier callers.
 */
function nibwp_etchwp_slugify(string $value): string
{
    if (!function_exists('sanitize_title')) {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($value)) ?: 'component');
    }
    return sanitize_title($value) ?: 'component';
}
