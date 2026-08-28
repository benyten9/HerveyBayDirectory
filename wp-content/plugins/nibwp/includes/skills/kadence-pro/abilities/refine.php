<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Kadence Pro — refine an existing layout. The agent reads the current post,
 * applies the user's written instruction to rebuild the Kadence block tree, then
 * submits the new tree here to re-validate + re-persist (update mode).
 */

require_once __DIR__ . '/../lib/converter.php';
require_once __DIR__ . '/../lib/validator.php';
require_once __DIR__ . '/../lib/scorer.php';
require_once __DIR__ . '/../lib/persister.php';

wp_register_ability('nibwp/kadence-pro-refine', [
    'label'       => __('Kadence Pro — refine a Kadence layout', 'nibwp'),
    'description' => __('Re-validate and re-persist an updated Kadence block tree onto an existing post (behind an idempotency marker). Use after the user asks to tweak a layout you built.', 'nibwp'),
    'category'    => 'kadence-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id'     => ['type' => 'integer', 'description' => 'The post/page to update.'],
            'tree'        => ['type' => 'array', 'description' => 'The revised Kadence block tree.', 'items' => ['type' => 'object']],
            'instruction' => ['type' => 'string', 'description' => 'The change the user asked for (recorded for audit).'],
            'title'       => ['type' => 'string', 'description' => 'Marker title (keeps updates idempotent).'],
            'dry_run'     => ['type' => 'boolean', 'default' => false],
            '_preflight_token' => ['type' => 'string'],
        ],
        'required' => ['post_id', 'tree'],
        'additionalProperties' => false,
    ],
    'execute_callback'    => 'nibwp_kadence_pro_refine',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true]],
]);

function nibwp_kadence_pro_refine(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('kadence-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }
    $post_id = (int) ($input['post_id'] ?? 0);
    $tree = (array) ($input['tree'] ?? []);
    if ($post_id <= 0 || $tree === []) {
        return new WP_Error('bad_input', 'post_id and a non-empty tree are required.');
    }

    $raw_token = (string) ($input['_preflight_token'] ?? '');
    if (!function_exists('nibwp_skill_preflight_consume_token')) {
        require_once __DIR__ . '/../../../abilities/skill-preflight.php';
    }
    $token_payload = nibwp_skill_preflight_consume_token($raw_token, 'kadence-pro');
    if (is_wp_error($token_payload)) {
        return ['success' => false, 'requires_user_input' => true, 'question' => 'Run nibwp/skill-preflight { skill_id:"kadence-pro" } first.', 'summary' => $token_payload->get_error_message()];
    }

    $blocks = nibwp_kadence_build_blocks($tree);
    $validation = nibwp_kadence_validate_blocks($blocks);
    $score = nibwp_kadence_score($blocks, $validation);
    if (!$validation['passed']) {
        nibwp_skill_preflight_bump_attempts($raw_token);
        return ['success' => false, 'validation' => $validation, 'unchecked_items' => $validation['failed'], 'score' => $score, 'summary' => 'Validation failed; fix and resubmit.'];
    }
    if (!empty($input['dry_run'])) {
        return ['success' => true, 'dry_run' => true, 'validation' => $validation, 'score' => $score, 'summary' => 'Passed. Resubmit dry_run:false to apply.'];
    }

    $result = nibwp_kadence_persist($blocks, ['mode' => 'update', 'post_id' => $post_id, 'title' => (string) ($input['title'] ?? '')]);
    if (is_wp_error($result)) {
        nibwp_skill_preflight_bump_attempts($raw_token);
        return $result;
    }
    if (!empty($input['instruction'])) {
        update_post_meta($post_id, '_nibwp_kadence_pro_last_refine', ['at' => time(), 'instruction' => sanitize_text_field((string) $input['instruction'])]);
    }
    nibwp_skill_preflight_clear_token($raw_token);
    return ['success' => true, 'result' => $result, 'score' => $score, 'edit_url' => $result['edit_url'] ?? '', 'summary' => 'Layout updated. Open the Kadence editor and Save to materialise.'];
}
