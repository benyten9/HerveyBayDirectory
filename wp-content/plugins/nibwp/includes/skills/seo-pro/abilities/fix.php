<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nibwp/seo-pro-fix', [
    'label'       => __('SEO Pro — Apply Fixes', 'nibwp'),
    'description' => __('Apply validated corrections surfaced by seo-pro-audit: set a canonical URL, toggle noindex/nofollow, write a missing title/description, set OG overrides — for one or many posts. Validate-gated. Run dry_run:true first, then dry_run:false.', 'nibwp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'items' => [
                'type' => 'array',
                'description' => 'Each: { id, title?, description?, canonical?, noindex?, nofollow?, focus_keyword?, og_title?, og_description? }.',
                'items' => ['type' => 'object'],
            ],
            'dry_run'          => ['type' => 'boolean', 'default' => true],
            '_preflight_token' => ['type' => 'string'],
        ],
        'required' => ['items'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_seo_pro_fix_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => nibwp_seo_pro_ability_meta(false, true, 'Will refuse to noindex the front page and rejects invalid canonicals / over-length copy. dry_run:true to preview, dry_run:false to commit.'),
]);

function nibwp_seo_pro_fix_execute(array $input): array|WP_Error
{
    $g = nibwp_seo_pro_guard($input, true);
    if (is_wp_error($g)) {
        return $g;
    }
    $out = nibwp_seo_pro_apply_items($input, $g['answers'], ['title', 'description', 'canonical', 'noindex', 'nofollow', 'focus_keyword', 'og_title', 'og_description']);
    if (is_wp_error($out)) {
        return $out;
    }
    if (!$out['dry_run'] && $out['all_ok']) {
        nibwp_seo_pro_clear_token($g['token']);
    }
    return $out;
}
