<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nibwp/seo-pro-migrate', [
    'label'       => __('SEO Pro — Migrate', 'nibwp'),
    'description' => __('Port SEO meta from one engine to another (Yoast / Rank Math / AIOSEO / SEOPress / Slim SEO). Actions are via dry_run: dry_run:true returns a per-post diff of what would be written; dry_run:false migrates. Reads from from_engine, writes to to_engine. Skips posts that already have data on the target unless overwrite:true.', 'nibwp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'from_engine' => ['type' => 'string', 'enum' => ['yoast', 'rankmath', 'aioseo', 'seopress', 'slimseo']],
            'to_engine'   => ['type' => 'string', 'enum' => ['yoast', 'rankmath', 'aioseo', 'seopress', 'slimseo']],
            'post_types'  => ['type' => 'array', 'items' => ['type' => 'string']],
            'post_ids'    => ['type' => 'array', 'items' => ['type' => 'integer']],
            'overwrite'   => ['type' => 'boolean', 'default' => false],
            'per_page'    => ['type' => 'integer', 'default' => 100],
            'page'        => ['type' => 'integer', 'default' => 1],
            'dry_run'     => ['type' => 'boolean', 'default' => true],
            '_preflight_token' => ['type' => 'string'],
        ],
        'required' => ['from_engine', 'to_engine'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_seo_pro_migrate_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => nibwp_seo_pro_ability_meta(false, true, 'Always dry_run:true first to review the diff. from_engine must have data; to_engine must be installed. Migrates title, description, focus keyword, canonical, OG, robots.'),
]);

function nibwp_seo_pro_migrate_execute(array $input): array|WP_Error
{
    $g = nibwp_seo_pro_guard($input, true);
    if (is_wp_error($g)) {
        return $g;
    }
    $from = (string) ($input['from_engine'] ?? '');
    $to   = (string) ($input['to_engine'] ?? '');
    if ($from === $to) {
        return nibwp_seo_pro_err('same_engine', 'from_engine and to_engine must differ.');
    }
    $present = array_column(nibwp_seo_pro_engines_present(), 'slug');
    if (!in_array($to, $present, true)) {
        return nibwp_seo_pro_err('target_missing', sprintf('Target engine "%s" is not installed/active.', $to), 409);
    }

    $ids = array_values(array_filter(array_map('intval', (array) ($input['post_ids'] ?? []))));
    if ($ids === []) {
        $per  = min(max((int) ($input['per_page'] ?? 100), 1), 100);
        $page = max((int) ($input['page'] ?? 1), 1);
        $q = new WP_Query([
            'post_type'      => (array) ($input['post_types'] ?? ['post', 'page']),
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => $per,
            'paged'          => $page,
            'fields'         => 'ids',
        ]);
        $ids = array_map('intval', $q->posts);
        $total = (int) $q->found_posts;
    } else {
        $total = count($ids);
    }

    $dry = !empty($input['dry_run']);
    $overwrite = !empty($input['overwrite']);
    $fields = ['title', 'description', 'focus_keyword', 'canonical', 'og_title', 'og_description', 'noindex', 'nofollow'];
    $rows = [];
    $migrated = 0;

    foreach ($ids as $pid) {
        $src = nibwp_seo_pro_read($pid, $from);
        $dst = nibwp_seo_pro_read($pid, $to);

        // Only carry non-empty source values.
        $payload = [];
        foreach ($fields as $f) {
            $val = $src[$f] ?? '';
            $is_set = is_bool($val) ? $val : ($val !== '' && $val !== null);
            if ($is_set) {
                $payload[$f] = $val;
            }
        }
        if ($payload === []) {
            continue;
        }
        // Skip when target already has a title/description unless overwrite.
        $target_has = ($dst['title'] ?? '') !== '' || ($dst['description'] ?? '') !== '';
        if ($target_has && !$overwrite) {
            $rows[] = ['post_id' => $pid, 'skipped' => 'target already populated'];
            continue;
        }
        if ($dry) {
            $rows[] = ['post_id' => $pid, 'title' => get_the_title($pid), 'would_write' => $payload];
        } else {
            $changed = nibwp_seo_pro_write($pid, $payload, $to);
            $rows[] = ['post_id' => $pid, 'migrated' => $changed];
            $migrated++;
        }
    }

    if (!$dry) {
        nibwp_seo_pro_clear_token($g['token']);
    }

    return [
        'dry_run'   => $dry,
        'from'      => $from,
        'to'        => $to,
        'scanned'   => count($ids),
        'total_in_scope' => $total,
        'changes'   => $rows,
        'migrated'  => $migrated,
    ];
}
