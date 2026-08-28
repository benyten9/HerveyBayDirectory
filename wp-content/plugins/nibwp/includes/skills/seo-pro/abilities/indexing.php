<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/../lib/external.php';

wp_register_ability('nibwp/seo-pro-indexing', [
    'label'       => __('SEO Pro — Instant Indexing', 'nibwp'),
    'description' => __('Submit URLs to search engines via IndexNow (Bing, Yandex, Seznam). Actions: submit (push a list of URLs), configure (store the IndexNow key). Requires an IndexNow key hosted at /{key}.txt on the site.', 'nibwp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['submit', 'configure']],
            'urls'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'For submit: absolute URLs (max 10000).'],
            'key'    => ['type' => 'string', 'description' => 'For configure: the IndexNow key.'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_seo_pro_indexing_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => nibwp_seo_pro_ability_meta(false, false, 'IndexNow notifies Bing/Yandex/Seznam instantly (Google does not use IndexNow). Host the key file at https://yoursite/{key}.txt before submitting.'),
]);

function nibwp_seo_pro_indexing_execute(array $input): array|WP_Error
{
    $g = nibwp_seo_pro_guard($input, false);
    if (is_wp_error($g)) {
        return $g;
    }
    $action = (string) ($input['action'] ?? '');

    if ($action === 'configure') {
        $key = sanitize_text_field((string) ($input['key'] ?? ''));
        if ($key === '') {
            return nibwp_seo_pro_err('no_key', 'Provide the IndexNow "key".');
        }
        nibwp_seo_pro_set_cred('indexnow', $key);
        return ['configured' => true, 'key_location' => home_url('/' . $key . '.txt'), 'note' => 'Create that key file (contents = the key) so search engines can verify ownership.'];
    }

    // submit
    $key = (string) nibwp_seo_pro_cred('indexnow');
    if ($key === '') {
        return nibwp_seo_pro_no_cred('IndexNow', 'Call this ability with action:configure + your key first.');
    }
    $urls = array_values(array_filter(array_map('esc_url_raw', (array) ($input['urls'] ?? []))));
    if ($urls === []) {
        return nibwp_seo_pro_err('no_urls', 'Provide a non-empty "urls" array.');
    }
    $host = wp_parse_url(home_url(), PHP_URL_HOST);
    $res = nibwp_seo_pro_http('https://api.indexnow.org/indexnow', [
        'method'  => 'POST',
        'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
        'body'    => wp_json_encode([
            'host'        => $host,
            'key'         => $key,
            'keyLocation' => home_url('/' . $key . '.txt'),
            'urlList'     => array_slice($urls, 0, 10000),
        ]),
    ]);
    if (is_wp_error($res)) {
        return $res;
    }
    return ['submitted' => count($urls), 'host' => $host, 'response' => $res];
}
