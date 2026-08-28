<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nibwp/seo-pro-links', [
    'label'       => __('SEO Pro — Internal Links', 'nibwp'),
    'description' => __('Improve internal linking. Actions: suggest (find relevant internal-link opportunities for a post), apply (insert a link into the post content for { anchor, target_url }), broken (check the post\'s links for 4xx/5xx). apply edits post content and is validate/preview gated.', 'nibwp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['suggest', 'apply', 'broken']],
            'post_id' => ['type' => 'integer'],
            'max'     => ['type' => 'integer', 'default' => 8],
            'items'   => ['type' => 'array', 'description' => 'For apply: [{ post_id, anchor, target_url }].', 'items' => ['type' => 'object']],
            'dry_run' => ['type' => 'boolean', 'default' => true],
            '_preflight_token' => ['type' => 'string'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_seo_pro_links_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => nibwp_seo_pro_ability_meta(false, true, 'suggest + broken are read-only. apply edits post_content — run dry_run:true to preview the inserted link, then dry_run:false.'),
]);

function nibwp_seo_pro_links_execute(array $input): array|WP_Error
{
    $action = (string) ($input['action'] ?? '');
    $g = nibwp_seo_pro_guard($input, $action === 'apply');
    if (is_wp_error($g)) {
        return $g;
    }

    if ($action === 'suggest') {
        $post_id = (int) ($input['post_id'] ?? 0);
        $post = get_post($post_id);
        if (!$post) {
            return nibwp_seo_pro_err('not_found', 'Post not found.', 404);
        }
        $text = ' ' . strtolower(wp_strip_all_tags($post->post_content)) . ' ';
        $max = min(max((int) ($input['max'] ?? 8), 1), 25);
        $candidates = get_posts(['post_type' => ['post', 'page'], 'post_status' => 'publish', 'numberposts' => 200, 'post__not_in' => [$post_id], 'fields' => 'ids']);
        $suggestions = [];
        foreach ($candidates as $cid) {
            $cid = (int) $cid;
            $ctitle = get_the_title($cid);
            if (mb_strlen($ctitle) < 6) { continue; }
            $needle = strtolower($ctitle);
            if (strpos($text, ' ' . $needle . ' ') === false && strpos($text, $needle) === false) { continue; }
            $url = get_permalink($cid);
            // Already linked?
            if (stripos($post->post_content, 'href="' . $url) !== false || stripos($post->post_content, rtrim((string) $url, '/')) !== false) { continue; }
            $suggestions[] = ['target_post_id' => $cid, 'target_title' => $ctitle, 'target_url' => $url, 'anchor' => $ctitle];
            if (count($suggestions) >= $max) { break; }
        }
        return ['post_id' => $post_id, 'suggestions' => $suggestions, 'count' => count($suggestions)];
    }

    if ($action === 'broken') {
        $post_id = (int) ($input['post_id'] ?? 0);
        $post = get_post($post_id);
        if (!$post) {
            return nibwp_seo_pro_err('not_found', 'Post not found.', 404);
        }
        $broken = [];
        if (preg_match_all('/<a\b[^>]*href\s*=\s*"([^"]+)"/i', $post->post_content, $m)) {
            $urls = array_slice(array_unique($m[1]), 0, 20);
            foreach ($urls as $url) {
                if (strpos($url, '#') === 0 || stripos($url, 'mailto:') === 0 || stripos($url, 'tel:') === 0) { continue; }
                $abs = strpos($url, 'http') === 0 ? $url : home_url($url);
                $resp = wp_remote_head($abs, ['timeout' => 8, 'redirection' => 3]);
                $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
                if ($code === 0 || $code >= 400) {
                    $broken[] = ['url' => $url, 'status' => $code ?: 'unreachable'];
                }
            }
        }
        return ['post_id' => $post_id, 'broken' => $broken, 'count' => count($broken)];
    }

    // apply
    $items = (array) ($input['items'] ?? []);
    if ($items === []) {
        return nibwp_seo_pro_err('no_items', 'Provide [{ post_id, anchor, target_url }] items.');
    }
    $dry = !empty($input['dry_run']);
    $results = [];
    $ok = true;
    foreach ($items as $it) {
        $it = (array) $it;
        $pid = (int) ($it['post_id'] ?? 0);
        $anchor = trim((string) ($it['anchor'] ?? ''));
        $target = esc_url_raw((string) ($it['target_url'] ?? ''));
        $post = $pid ? get_post($pid) : null;
        if (!$post || $anchor === '' || $target === '') {
            $results[] = ['post_id' => $pid, 'error' => 'need post_id + anchor + target_url']; $ok = false; continue;
        }
        $content = $post->post_content;
        // Match the anchor as plain text not already inside a tag/attribute.
        $pattern = '/(?<![\w">])(' . preg_quote($anchor, '/') . ')(?![^<]*>)(?![\w])/';
        if (!preg_match($pattern, $content)) {
            $results[] = ['post_id' => $pid, 'applied' => false, 'reason' => 'anchor text not found as linkable plain text']; $ok = false; continue;
        }
        $link = '<a href="' . esc_url($target) . '">' . esc_html($anchor) . '</a>';
        $new = preg_replace($pattern, $link, $content, 1);
        if ($dry) {
            $results[] = ['post_id' => $pid, 'applied' => false, 'preview' => $link];
        } else {
            wp_update_post(['ID' => $pid, 'post_content' => wp_slash((string) $new)]);
            $results[] = ['post_id' => $pid, 'applied' => true, 'link' => $link];
        }
    }
    if (!$dry && $ok) {
        nibwp_seo_pro_clear_token($g['token']);
    }
    return ['dry_run' => $dry, 'all_ok' => $ok, 'results' => $results];
}
