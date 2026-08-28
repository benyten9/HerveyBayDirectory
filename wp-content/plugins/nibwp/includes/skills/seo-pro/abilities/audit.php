<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nibwp/seo-pro-audit', [
    'label'       => __('SEO Pro — Audit', 'nibwp'),
    'description' => __('Scan posts/pages and return a scored SEO report card plus a prioritized fix queue (missing/long titles + descriptions, missing H1/alt/canonical, noindex mistakes, thin content). Engine-agnostic. Read-only — the entry point of the SEO Pro pipeline.', 'nibwp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_types' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Defaults to post + page.'],
            'post_ids'   => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Audit only these posts (overrides post_types).'],
            'per_page'   => ['type' => 'integer', 'default' => 25],
            'page'       => ['type' => 'integer', 'default' => 1],
            'title_max'  => ['type' => 'integer', 'default' => 60],
            'desc_max'   => ['type' => 'integer', 'default' => 160],
            'min_words'  => ['type' => 'integer', 'default' => 300],
        ],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_seo_pro_audit_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => nibwp_seo_pro_ability_meta(true, false, 'Run first. Returns site_score, per-post scores + issues, and a prioritized fix_queue. Feed fix_queue items into seo-pro-fix / seo-pro-meta / seo-pro-schema.'),
]);

function nibwp_seo_pro_audit_execute(array $input): array|WP_Error
{
    $g = nibwp_seo_pro_guard($input, false);
    if (is_wp_error($g)) {
        return $g;
    }

    $opts = [
        'title_max' => (int) ($input['title_max'] ?? 60),
        'title_min' => (int) ($input['title_min'] ?? 30),
        'desc_max'  => (int) ($input['desc_max'] ?? 160),
        'desc_min'  => (int) ($input['desc_min'] ?? 50),
        'min_words' => (int) ($input['min_words'] ?? 300),
    ];

    $ids = array_values(array_filter(array_map('intval', (array) ($input['post_ids'] ?? []))));
    if ($ids === []) {
        $per  = min(max((int) ($input['per_page'] ?? 25), 1), 100);
        $page = max((int) ($input['page'] ?? 1), 1);
        $q = new WP_Query([
            'post_type'      => (array) ($input['post_types'] ?? ['post', 'page']),
            'post_status'    => 'publish',
            'posts_per_page' => $per,
            'paged'          => $page,
            'fields'         => 'ids',
            'no_found_rows'  => false,
        ]);
        $ids = array_map('intval', $q->posts);
        $total = (int) $q->found_posts;
    } else {
        $total = count($ids);
    }

    $results = [];
    $posts = [];
    $fix_queue = [];
    foreach ($ids as $pid) {
        $rec = nibwp_seo_pro_read($pid);
        $score = nibwp_seo_pro_score_post($pid, $rec, $opts + ['is_front' => nibwp_seo_pro_is_front($pid)]);
        $results[] = $score;
        $title = $rec['title'] !== '' ? $rec['title'] : get_the_title($pid);
        $posts[] = [
            'id'     => $pid,
            'title'  => $title,
            'score'  => $score['score'],
            'issues' => $score['issues'],
            'edit'   => get_edit_post_link($pid, 'raw'),
        ];
        foreach ($score['issues'] as $iss) {
            $fix_queue[] = ['post_id' => $pid, 'post_title' => $title] + $iss;
        }
    }

    // Prioritize the fix queue: high → medium → low.
    $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
    usort($fix_queue, static fn ($a, $b) => ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9));

    $engine = nibwp_seo_pro_engine();

    return [
        'engine'     => $engine['name'] ?? '(none — using core WP only)',
        'site'       => nibwp_seo_pro_score_site($results),
        'audited'    => count($posts),
        'total_in_scope' => $total,
        'posts'      => $posts,
        'fix_queue'  => array_slice($fix_queue, 0, 50),
        'next'       => 'Pass fix_queue items to seo-pro-fix (canonical/robots), seo-pro-meta (titles/descriptions), seo-pro-schema (structured data), or seo-pro-alttext (image alt).',
    ];
}
