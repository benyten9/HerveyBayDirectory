<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nibwp/seo-pro-alttext', [
    'label'       => __('SEO Pro — Image Alt Text', 'nibwp'),
    'description' => __('Audit images missing alt text and commit AI-written alt text. Actions: audit (list attachments / in-content images with no alt), set (write alt for { attachment_id, alt } items). You generate the alt from the image + page context per the playbook; this validates + persists to the attachment.', 'nibwp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['audit', 'set']],
            'per_page' => ['type' => 'integer', 'default' => 50],
            'page'     => ['type' => 'integer', 'default' => 1],
            'items'    => ['type' => 'array', 'description' => 'For set: [{ attachment_id, alt }].', 'items' => ['type' => 'object']],
            'dry_run'  => ['type' => 'boolean', 'default' => true],
            '_preflight_token' => ['type' => 'string'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_seo_pro_alttext_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => nibwp_seo_pro_ability_meta(false, true, 'Alt text must be 1-125 chars and describe the image in context (not keyword-stuffed). audit is read-only; set needs a preflight token.'),
]);

function nibwp_seo_pro_alttext_execute(array $input): array|WP_Error
{
    $action = (string) ($input['action'] ?? '');
    $g = nibwp_seo_pro_guard($input, $action === 'set');
    if (is_wp_error($g)) {
        return $g;
    }

    if ($action === 'audit') {
        $per  = min(max((int) ($input['per_page'] ?? 50), 1), 100);
        $page = max((int) ($input['page'] ?? 1), 1);
        $q = new WP_Query([
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'posts_per_page' => $per,
            'paged'          => $page,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'OR',
                ['key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS'],
                ['key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '='],
            ],
        ]);
        $items = [];
        foreach ($q->posts as $aid) {
            $aid = (int) $aid;
            $items[] = [
                'attachment_id' => $aid,
                'file'          => wp_get_attachment_url($aid),
                'title'         => get_the_title($aid),
                'parent'        => (int) wp_get_post_parent_id($aid),
            ];
        }
        return ['missing_alt' => $items, 'count' => count($items), 'total' => (int) $q->found_posts, 'next' => 'Generate descriptive alt for each, then call set with [{attachment_id, alt}].'];
    }

    // set
    $items = (array) ($input['items'] ?? []);
    if ($items === []) {
        return nibwp_seo_pro_err('no_items', 'Provide [{ attachment_id, alt }] items.');
    }
    $dry = !empty($input['dry_run']);
    $results = [];
    $ok = true;
    foreach ($items as $it) {
        $it  = (array) $it;
        $aid = (int) ($it['attachment_id'] ?? 0);
        $alt = trim((string) ($it['alt'] ?? ''));
        $len = function_exists('mb_strlen') ? mb_strlen($alt) : strlen($alt);
        if (!$aid || get_post_type($aid) !== 'attachment') {
            $results[] = ['attachment_id' => $aid, 'error' => 'not an attachment']; $ok = false; continue;
        }
        if ($alt === '' || $len > 125) {
            $results[] = ['attachment_id' => $aid, 'passed' => false, 'reason' => $alt === '' ? 'alt is empty' : sprintf('alt is %d chars (max 125)', $len)]; $ok = false; continue;
        }
        if ($dry) {
            $results[] = ['attachment_id' => $aid, 'passed' => true, 'alt' => $alt];
        } else {
            update_post_meta($aid, '_wp_attachment_image_alt', sanitize_text_field($alt));
            $results[] = ['attachment_id' => $aid, 'updated' => true, 'alt' => $alt];
        }
    }
    if (!$dry && $ok) {
        nibwp_seo_pro_clear_token($g['token']);
    }
    return ['dry_run' => $dry, 'all_ok' => $ok, 'results' => $results, 'count' => count($results)];
}
