<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/../lib/external.php';

wp_register_ability('nibwp/seo-pro-serp', [
    'label'       => __('SEO Pro — SERP Intent Brief', 'nibwp'),
    'description' => __('Build a content brief for a keyword from live SERP data: the People-Also-Ask questions, related searches, and the headings/angle of the ranking pages. Actions: brief, configure (store a SERP provider key — SerpApi). Requires a provider key.', 'nibwp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['brief', 'configure']],
            'keyword'  => ['type' => 'string'],
            'provider' => ['type' => 'string', 'enum' => ['serpapi'], 'description' => 'For configure.'],
            'key'      => ['type' => 'string', 'description' => 'For configure: the provider API key.'],
            'locale'   => ['type' => 'string', 'description' => 'e.g. en-US.'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_seo_pro_serp_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => nibwp_seo_pro_ability_meta(true, false, 'Read-only. Returns PAA questions + related searches + ranking titles to shape an outline. Use it before writing or with seo-pro-optimize.'),
]);

function nibwp_seo_pro_serp_execute(array $input): array|WP_Error
{
    $g = nibwp_seo_pro_guard($input, false);
    if (is_wp_error($g)) {
        return $g;
    }
    $action = (string) ($input['action'] ?? '');

    if ($action === 'configure') {
        $key = (string) ($input['key'] ?? '');
        if ($key === '') {
            return nibwp_seo_pro_err('no_key', 'Provide the provider "key".');
        }
        nibwp_seo_pro_set_cred('serp', ['provider' => (string) ($input['provider'] ?? 'serpapi'), 'key' => $key]);
        return ['configured' => true, 'provider' => (string) ($input['provider'] ?? 'serpapi')];
    }

    // brief
    $cred = nibwp_seo_pro_cred('serp');
    if (!is_array($cred) || empty($cred['key'])) {
        return nibwp_seo_pro_no_cred('SERP provider', 'Call action:configure with a SerpApi key first.');
    }
    $kw = trim((string) ($input['keyword'] ?? ''));
    if ($kw === '') {
        return nibwp_seo_pro_err('no_keyword', 'Provide a "keyword".');
    }
    [$hl, $gl] = nibwp_seo_pro_locale_parts((string) ($input['locale'] ?? get_bloginfo('language')));

    $url = add_query_arg([
        'engine'  => 'google',
        'q'       => rawurlencode($kw),
        'hl'      => $hl,
        'gl'      => $gl,
        'api_key' => $cred['key'],
    ], 'https://serpapi.com/search.json');
    $res = nibwp_seo_pro_http($url, ['timeout' => 25]);
    if (is_wp_error($res)) {
        return $res;
    }

    $paa = [];
    foreach ((array) ($res['related_questions'] ?? []) as $q) {
        if (!empty($q['question'])) { $paa[] = (string) $q['question']; }
    }
    $related = [];
    foreach ((array) ($res['related_searches'] ?? []) as $r) {
        if (!empty($r['query'])) { $related[] = (string) $r['query']; }
    }
    $titles = [];
    $word_counts = [];
    foreach (array_slice((array) ($res['organic_results'] ?? []), 0, 10) as $o) {
        if (!empty($o['title'])) { $titles[] = (string) $o['title']; }
    }

    return [
        'keyword'         => $kw,
        'people_also_ask' => array_slice($paa, 0, 12),
        'related_searches'=> array_slice($related, 0, 12),
        'ranking_titles'  => $titles,
        'suggested_outline' => array_values(array_unique(array_merge(array_slice($paa, 0, 6)))),
        'next'            => 'Use the PAA as H2/H3 and the related searches as entities. Pair with seo-pro-optimize or seo-pro-meta.',
    ];
}

/** Split a locale like en-US into [hl, gl]. */
function nibwp_seo_pro_locale_parts(string $locale): array
{
    $locale = str_replace('_', '-', $locale);
    $parts = explode('-', $locale);
    $hl = strtolower($parts[0] ?? 'en') ?: 'en';
    $gl = strtolower($parts[1] ?? $hl) ?: 'us';
    return [$hl, $gl];
}
