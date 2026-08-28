<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nibwp/seo-pro-optimize', [
    'label'       => __('SEO Pro — On-page Optimize', 'nibwp'),
    'description' => __('Optimize a single post for a target keyword: analyze keyword placement (title, description, H1, subheadings, first paragraph, density), score before vs after a proposed title/description, validate, and persist. Returns content-level recommendations for the agent to apply separately.', 'nibwp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id'        => ['type' => 'integer'],
            'target_keyword' => ['type' => 'string'],
            'title'          => ['type' => 'string', 'description' => 'Proposed optimized SEO title.'],
            'description'    => ['type' => 'string', 'description' => 'Proposed optimized meta description.'],
            'dry_run'        => ['type' => 'boolean', 'default' => true],
            '_preflight_token' => ['type' => 'string'],
        ],
        'required' => ['post_id', 'target_keyword'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_seo_pro_optimize_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => nibwp_seo_pro_ability_meta(false, true, 'dry_run:true returns analysis + before/after score + recommendations; dry_run:false persists the proposed title/description + sets the focus keyword.'),
]);

function nibwp_seo_pro_optimize_execute(array $input): array|WP_Error
{
    $g = nibwp_seo_pro_guard($input, true);
    if (is_wp_error($g)) {
        return $g;
    }
    $post_id = (int) ($input['post_id'] ?? 0);
    $post = get_post($post_id);
    if (!$post) {
        return nibwp_seo_pro_err('not_found', 'Post not found.', 404);
    }
    $kw = trim((string) ($input['target_keyword'] ?? ''));
    if ($kw === '') {
        return nibwp_seo_pro_err('no_keyword', 'Provide a target_keyword.');
    }

    $opts = nibwp_seo_pro_opts($g['answers']);
    $rec  = nibwp_seo_pro_read($post_id);
    $proposed = [
        'title'         => array_key_exists('title', $input) ? (string) $input['title'] : $rec['title'],
        'description'   => array_key_exists('description', $input) ? (string) $input['description'] : $rec['description'],
        'focus_keyword' => $kw,
    ];

    $analysis = nibwp_seo_pro_kw_analysis($post, $kw, $proposed);
    $score_opts = ['title_max' => $opts['title_max'], 'desc_max' => $opts['desc_max'], 'min_words' => $opts['min_words'], 'is_front' => nibwp_seo_pro_is_front($post_id)];
    $before = nibwp_seo_pro_score_post($post_id, $rec, $score_opts)['score'];
    $after  = nibwp_seo_pro_score_post($post_id, array_merge($rec, $proposed), $score_opts)['score'];

    $recommendations = [];
    if (!$analysis['in_title']) { $recommendations[] = 'Add the target keyword to the SEO title (near the front).'; }
    if (!$analysis['in_meta_description']) { $recommendations[] = 'Include the target keyword in the meta description.'; }
    if (!$analysis['in_h1']) { $recommendations[] = 'Work the keyword into the H1 / main heading.'; }
    if (!$analysis['in_first_100_words']) { $recommendations[] = 'Mention the keyword within the first 100 words of the body.'; }
    if ($analysis['density_pct'] < 0.3) { $recommendations[] = 'Keyword density is low — add a few natural occurrences / synonyms.'; }
    if ($analysis['density_pct'] > 3) { $recommendations[] = 'Keyword density is high — reduce to avoid over-optimization.'; }

    if (!empty($input['dry_run'])) {
        $verdict = nibwp_seo_pro_validate($proposed, ['title_max' => $opts['title_max'], 'desc_max' => $opts['desc_max'], 'post_id' => $post_id, 'existing_titles' => nibwp_seo_pro_title_index($opts['post_types'])]);
        return [
            'dry_run'  => true,
            'post_id'  => $post_id,
            'analysis' => $analysis,
            'score'    => ['before' => $before, 'after' => $after],
            'passed'   => $verdict['passed'],
            'failed'   => $verdict['failed'],
            'recommendations' => $recommendations,
        ];
    }

    $persist = nibwp_seo_pro_persist($post_id, $proposed, [
        'title_max' => $opts['title_max'], 'desc_max' => $opts['desc_max'], 'post_id' => $post_id,
        'existing_titles' => nibwp_seo_pro_title_index($opts['post_types']),
    ]);
    if (is_wp_error($persist)) {
        return $persist;
    }
    nibwp_seo_pro_clear_token($g['token']);
    return ['post_id' => $post_id, 'persisted' => true, 'changed' => $persist['changed'], 'score' => ['before' => $before, 'after' => $after], 'analysis' => $analysis, 'recommendations' => $recommendations];
}

/**
 * Light keyword-placement analysis for a post + a proposed meta set.
 *
 * @param array<string,mixed> $proposed
 */
function nibwp_seo_pro_kw_analysis(WP_Post $post, string $kw, array $proposed = []): array
{
    $content = wp_strip_all_tags($post->post_content);
    $wc  = $content === '' ? 0 : str_word_count($content);
    $kwl = strtolower($kw);
    $cl  = strtolower($content);
    $count = $kwl === '' ? 0 : substr_count($cl, $kwl);
    $first = strtolower(implode(' ', array_slice(preg_split('/\s+/', $content) ?: [], 0, 100)));
    $title = (string) ($proposed['title'] ?? '') !== '' ? (string) $proposed['title'] : $post->post_title;
    $desc  = (string) ($proposed['description'] ?? '');
    $q = preg_quote($kw, '/');

    return [
        'word_count'          => $wc,
        'keyword'             => $kw,
        'occurrences'         => $count,
        'density_pct'         => $wc > 0 ? round($count / $wc * 100, 2) : 0.0,
        'in_title'            => $kw !== '' && stripos($title, $kw) !== false,
        'in_meta_description' => $kw !== '' && $desc !== '' && stripos($desc, $kw) !== false,
        'in_h1'               => (bool) preg_match('/<h1[^>]*>[^<]*' . $q . '/i', $post->post_content),
        'in_subheadings'      => (bool) preg_match('/<h[2-3][^>]*>[^<]*' . $q . '/i', $post->post_content),
        'in_first_100_words'  => $kwl !== '' && strpos($first, $kwl) !== false,
    ];
}
