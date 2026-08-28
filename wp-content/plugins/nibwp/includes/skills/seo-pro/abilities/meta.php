<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nibwp/seo-pro-meta', [
    'label'       => __('SEO Pro — Meta Generation', 'nibwp'),
    'description' => __('Commit AI-generated, brand-voice SEO titles + meta descriptions (and optional focus keyword / OG overrides) for one or many posts. You synthesize the copy per the playbook; this ability validates length + uniqueness then writes via the active SEO engine. Always dry_run:true first, fix any failures, then dry_run:false.', 'nibwp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'items' => [
                'type' => 'array',
                'description' => 'Each: { id, title?, description?, focus_keyword?, og_title?, og_description? }.',
                'items' => ['type' => 'object'],
            ],
            'dry_run'          => ['type' => 'boolean', 'default' => true],
            '_preflight_token' => ['type' => 'string'],
        ],
        'required' => ['items'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_seo_pro_meta_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => nibwp_seo_pro_ability_meta(false, true, 'Titles must be unique and within the length limits or validation fails. Run dry_run:true, patch failed[], then dry_run:false to persist. Do NOT write SEO meta via wp-update-post.'),
]);

function nibwp_seo_pro_meta_execute(array $input): array|WP_Error
{
    $g = nibwp_seo_pro_guard($input, true);
    if (is_wp_error($g)) {
        return $g;
    }
    $out = nibwp_seo_pro_apply_items($input, $g['answers'], ['title', 'description', 'focus_keyword', 'og_title', 'og_description']);
    if (is_wp_error($out)) {
        return $out;
    }
    if (!$out['dry_run'] && $out['all_ok']) {
        nibwp_seo_pro_clear_token($g['token']);
    }
    $out['next'] = $out['dry_run']
        ? 'Review results[].failed; patch the copy and re-run with dry_run:false to persist.'
        : 'Persisted. Re-run seo-pro-audit to confirm the score improved.';
    return $out;
}
