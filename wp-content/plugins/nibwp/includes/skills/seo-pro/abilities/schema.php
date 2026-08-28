<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nibwp/seo-pro-schema', [
    'label'       => __('SEO Pro — Structured Data', 'nibwp'),
    'description' => __('Recommend, generate, validate and persist JSON-LD structured data per post. Actions: recommend (suggest the best @type + a prefilled skeleton), get, set (validate required schema.org fields then store + render), delete. The schema renders on the front end regardless of the active SEO plugin.', 'nibwp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['recommend', 'get', 'set', 'delete']],
            'post_id' => ['type' => 'integer'],
            'schema'  => ['description' => 'For set: the JSON-LD object (or array of objects) to store.'],
            'dry_run' => ['type' => 'boolean', 'default' => true],
            '_preflight_token' => ['type' => 'string'],
        ],
        'required' => ['action', 'post_id'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_seo_pro_schema_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => nibwp_seo_pro_ability_meta(false, true, 'set validates required schema.org fields per @type. Call recommend first to get a prefilled skeleton, fill it, then set dry_run:true and finally dry_run:false.'),
]);

function nibwp_seo_pro_schema_execute(array $input): array|WP_Error
{
    $action = (string) ($input['action'] ?? '');
    $need_token = !in_array($action, ['recommend', 'get'], true);
    $g = nibwp_seo_pro_guard($input, $need_token);
    if (is_wp_error($g)) {
        return $g;
    }
    $post_id = (int) ($input['post_id'] ?? 0);
    $post = get_post($post_id);
    if (!$post) {
        return nibwp_seo_pro_err('not_found', 'Post not found.', 404);
    }

    switch ($action) {
        case 'get':
            return ['post_id' => $post_id, 'schema' => get_post_meta($post_id, '_nibwp_seo_pro_schema', true)];

        case 'recommend':
            return nibwp_seo_pro_schema_recommend($post);

        case 'set':
            if (!array_key_exists('schema', $input)) {
                return nibwp_seo_pro_err('no_schema', 'Provide a "schema" object.');
            }
            $verdict = nibwp_seo_pro_validate(['schema' => $input['schema']], ['post_id' => $post_id]);
            if (!empty($input['dry_run'])) {
                return ['dry_run' => true, 'passed' => $verdict['passed'], 'failed' => $verdict['failed']];
            }
            if (!$verdict['passed']) {
                return new WP_Error('schema_invalid', 'Schema failed validation — fix and resubmit.', ['status' => 422, 'failed' => $verdict['failed']]);
            }
            $schema = is_string($input['schema']) ? $input['schema'] : wp_json_encode($input['schema']);
            update_post_meta($post_id, '_nibwp_seo_pro_schema', wp_slash((string) $schema));
            nibwp_seo_pro_clear_token($g['token']);
            return ['set' => true, 'post_id' => $post_id, 'renders_on' => get_permalink($post_id)];

        case 'delete':
            delete_post_meta($post_id, '_nibwp_seo_pro_schema');
            nibwp_seo_pro_clear_token($g['token']);
            return ['deleted' => true, 'post_id' => $post_id];
    }
    return nibwp_seo_pro_err('bad_action', 'Unknown action: ' . $action);
}

/** Suggest a schema @type + a prefilled skeleton for a post. */
function nibwp_seo_pro_schema_recommend(WP_Post $post): array
{
    $post_id = $post->ID;
    $is_product = $post->post_type === 'product' || (function_exists('wc_get_product') && $post->post_type === 'product');
    $img = get_the_post_thumbnail_url($post_id, 'full') ?: '';
    $author = get_the_author_meta('display_name', (int) $post->post_author);
    $published = get_post_time('c', true, $post_id);
    $modified  = get_post_modified_time('c', true, $post_id);

    // Detect an FAQ pattern (multiple "?" headings) for a FAQPage suggestion.
    $faq = substr_count($post->post_content, '?') >= 3 && preg_match('/<h[2-4][^>]*>[^<]*\?/i', $post->post_content);

    if ($is_product) {
        $type = 'Product';
        $skeleton = [
            '@context' => 'https://schema.org', '@type' => 'Product',
            'name' => get_the_title($post_id), 'image' => $img,
            'description' => wp_strip_all_tags(get_the_excerpt($post_id)),
            'offers' => ['@type' => 'Offer', 'price' => '', 'priceCurrency' => '', 'availability' => 'https://schema.org/InStock', 'url' => get_permalink($post_id)],
        ];
    } elseif ($faq) {
        $type = 'FAQPage';
        $skeleton = [
            '@context' => 'https://schema.org', '@type' => 'FAQPage',
            'mainEntity' => [['@type' => 'Question', 'name' => '', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => '']]],
        ];
    } elseif ($post->post_type === 'page') {
        $type = 'WebPage';
        $skeleton = ['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => get_the_title($post_id), 'url' => get_permalink($post_id)];
    } else {
        $type = 'Article';
        $skeleton = [
            '@context' => 'https://schema.org', '@type' => 'Article',
            'headline' => get_the_title($post_id), 'image' => $img,
            'datePublished' => $published, 'dateModified' => $modified,
            'author' => ['@type' => 'Person', 'name' => $author],
            'publisher' => ['@type' => 'Organization', 'name' => get_bloginfo('name')],
            'mainEntityOfPage' => get_permalink($post_id),
        ];
    }

    return [
        'post_id'        => $post_id,
        'recommended_type' => $type,
        'reason'         => $is_product ? 'WooCommerce product' : ($faq ? 'Q&A headings detected' : ($post->post_type === 'page' ? 'static page' : 'blog/article post')),
        'skeleton'       => $skeleton,
        'required_fields' => nibwp_seo_pro_schema_required()[$type] ?? [],
        'next'           => 'Fill the skeleton, then call set with dry_run:true to validate, then dry_run:false.',
    ];
}
