<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nibwp/seo-pro-gate', [
    'label'       => __('SEO Pro — Pre-publish Gate', 'nibwp'),
    'description' => __('Validate a post (or a draft payload) against the SEO checklist before publishing: title/description length, canonical validity, robots sanity, duplicate titles, schema required fields, thin content, missing alt. Returns pass/fail + score + the exact failures. Read-only.', 'nibwp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id'     => ['type' => 'integer'],
            'title'       => ['type' => 'string', 'description' => 'Optional draft override to validate instead of the stored value.'],
            'description' => ['type' => 'string'],
            'canonical'   => ['type' => 'string'],
            'noindex'     => ['type' => 'boolean'],
            'schema'      => ['description' => 'Optional schema object/array to validate.'],
            'title_max'   => ['type' => 'integer', 'default' => 60],
            'desc_max'    => ['type' => 'integer', 'default' => 160],
        ],
        'required' => ['post_id'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_seo_pro_gate_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => nibwp_seo_pro_ability_meta(true, false, 'Call before publishing. passed=false means do not publish until the failed[] items are fixed.'),
]);

function nibwp_seo_pro_gate_execute(array $input): array|WP_Error
{
    $g = nibwp_seo_pro_guard($input, false);
    if (is_wp_error($g)) {
        return $g;
    }
    $post_id = (int) ($input['post_id'] ?? 0);
    if (!get_post($post_id)) {
        return nibwp_seo_pro_err('not_found', 'Post not found.', 404);
    }

    $rec = nibwp_seo_pro_read($post_id);
    // Draft overrides take precedence over stored values for the check.
    $payload = [
        'post_id'     => $post_id,
        'title'       => array_key_exists('title', $input) ? (string) $input['title'] : $rec['title'],
        'description' => array_key_exists('description', $input) ? (string) $input['description'] : $rec['description'],
        'canonical'   => array_key_exists('canonical', $input) ? (string) $input['canonical'] : $rec['canonical'],
        'noindex'     => array_key_exists('noindex', $input) ? (bool) $input['noindex'] : (bool) $rec['noindex'],
    ];
    if (array_key_exists('schema', $input)) {
        $payload['schema'] = $input['schema'];
    }

    $opts = [
        'title_max' => (int) ($input['title_max'] ?? 60),
        'desc_max'  => (int) ($input['desc_max'] ?? 160),
        'min_words' => (int) ($input['min_words'] ?? 300),
    ];
    $ctx = $opts + [
        'post_id'         => $post_id,
        'is_front'        => nibwp_seo_pro_is_front($post_id),
        'existing_titles' => nibwp_seo_pro_title_index((array) ($input['post_types'] ?? ['post', 'page'])),
    ];

    $verdict = nibwp_seo_pro_validate($payload, $ctx);
    $score   = nibwp_seo_pro_score_post($post_id, array_merge($rec, $payload), $opts + ['is_front' => $ctx['is_front']]);

    return [
        'post_id'  => $post_id,
        'passed'   => $verdict['passed'],
        'score'    => $score['score'],
        'failed'   => $verdict['failed'],
        'warnings' => array_merge($verdict['warnings'], array_map(static fn ($i) => ['id' => $i['id'], 'msg' => $i['msg'], 'severity' => $i['severity']], $score['issues'])),
    ];
}
