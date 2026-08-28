<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Migration Toolkit — tools for migrating, exporting, importing,
 * and cloning WordPress data between environments.
 */

// ═══════════════════════════════════════════════════════════════
// 1. EXPORT CONTENT — Export posts/pages/CPTs to JSON
// ═══════════════════════════════════════════════════════════════

wp_register_ability('nibwp/migration-export-content', [
    'label' => __('Export Content to JSON', domain: 'nibwp'),
    'description' => __('Exports posts, pages, or custom post types to a JSON-serializable array including meta fields, taxonomy terms, and featured images.', domain: 'nibwp'),
    'category' => 'migration',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_type' => ['type' => 'string', 'description' => 'Post type to export.', 'default' => 'post'],
            'status' => ['type' => 'string', 'description' => 'Post status filter (publish, draft, any, etc.).', 'default' => 'any'],
            'per_page' => ['type' => 'integer', 'description' => 'Number of posts to export (max 500).', 'default' => 50, 'maximum' => 500],
            'include_meta' => ['type' => 'boolean', 'description' => 'Include all post meta fields.', 'default' => true],
            'include_terms' => ['type' => 'boolean', 'description' => 'Include taxonomy terms.', 'default' => true],
            'include_media' => ['type' => 'boolean', 'description' => 'Include attached media URLs.', 'default' => false],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'post_type' => ['type' => 'string'],
            'count' => ['type' => 'integer'],
            'posts' => ['type' => 'array', 'description' => 'Array of exported post objects.'],
        ],
    ],
    'execute_callback' => 'nibwp_migration_export_content',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Exports posts/pages/CPTs to a portable JSON format.\nUse post_type to target specific content (post, page, product, etc.).\nSet include_meta=true to capture custom fields.\nSet include_terms=true to capture categories, tags, and custom taxonomies.\nSet include_media=true to include attached media URLs (slower).\nThe output can be fed directly into migration-import-content.\nMax 500 posts per call — paginate if needed.",
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_migration_export_content(array $input): array|WP_Error
{
    $post_type = sanitize_key($input['post_type'] ?? 'post');
    $status = sanitize_key($input['status'] ?? 'any');
    $per_page = min((int) ($input['per_page'] ?? 50), 500);
    $include_meta = $input['include_meta'] ?? true;
    $include_terms = $input['include_terms'] ?? true;
    $include_media = $input['include_media'] ?? false;

    if (!post_type_exists($post_type)) {
        return new WP_Error('invalid_post_type', "Post type '{$post_type}' does not exist.");
    }

    $query = new WP_Query([
        'post_type' => $post_type,
        'post_status' => $status,
        'posts_per_page' => $per_page,
        'orderby' => 'ID',
        'order' => 'ASC',
        'no_found_rows' => false,
    ]);

    $posts = [];
    foreach ($query->posts as $post) {
        $item = [
            'ID' => $post->ID,
            'post_title' => $post->post_title,
            'post_name' => $post->post_name,
            'post_content' => $post->post_content,
            'post_excerpt' => $post->post_excerpt,
            'post_status' => $post->post_status,
            'post_type' => $post->post_type,
            'post_date' => $post->post_date,
            'post_modified' => $post->post_modified,
            'post_author' => (int) $post->post_author,
            'post_parent' => (int) $post->post_parent,
            'menu_order' => (int) $post->menu_order,
            'comment_status' => $post->comment_status,
            'ping_status' => $post->ping_status,
        ];

        // Featured image.
        $thumb_id = get_post_thumbnail_id($post->ID);
        if ($thumb_id) {
            $item['featured_image_url'] = wp_get_attachment_url($thumb_id);
        }

        // Meta.
        if ($include_meta) {
            $meta = get_post_meta($post->ID);
            $item['meta'] = [];
            foreach ($meta as $key => $values) {
                if (str_starts_with($key, '_edit_') || $key === '_wp_old_slug') {
                    continue;
                }
                $item['meta'][$key] = count($values) === 1 ? $values[0] : $values;
            }
        }

        // Terms.
        if ($include_terms) {
            $taxonomies = get_object_taxonomies($post_type);
            $item['terms'] = [];
            foreach ($taxonomies as $taxonomy) {
                $terms = wp_get_object_terms($post->ID, $taxonomy, ['fields' => 'all']);
                if (!is_wp_error($terms) && !empty($terms)) {
                    $item['terms'][$taxonomy] = array_map(fn($t) => [
                        'name' => $t->name,
                        'slug' => $t->slug,
                    ], $terms);
                }
            }
        }

        // Attached media.
        if ($include_media) {
            $attachments = get_children([
                'post_parent' => $post->ID,
                'post_type' => 'attachment',
                'posts_per_page' => -1,
            ]);
            $item['media'] = [];
            foreach ($attachments as $att) {
                $item['media'][] = [
                    'title' => $att->post_title,
                    'url' => wp_get_attachment_url($att->ID),
                    'mime_type' => $att->post_mime_type,
                ];
            }
        }

        $posts[] = $item;
    }

    return [
        'success' => true,
        'post_type' => $post_type,
        'count' => count($posts),
        'total_found' => (int) $query->found_posts,
        'posts' => $posts,
    ];
}

// ═══════════════════════════════════════════════════════════════
// 2. IMPORT CONTENT — Import posts/pages from JSON
// ═══════════════════════════════════════════════════════════════

wp_register_ability('nibwp/migration-import-content', [
    'label' => __('Import Content from JSON', domain: 'nibwp'),
    'description' => __('Imports posts, pages, or CPTs from a JSON array (as produced by the export tool), including meta fields and taxonomy terms.', domain: 'nibwp'),
    'category' => 'migration',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'data' => ['type' => 'array', 'description' => 'Array of post objects from migration-export-content.'],
            'post_type' => ['type' => 'string', 'description' => 'Override post type for all imported items.', 'default' => ''],
            'status_override' => ['type' => 'string', 'description' => 'Override status for all imported items (e.g. draft).', 'default' => ''],
            'update_existing' => ['type' => 'boolean', 'description' => 'If true, update existing posts matched by slug instead of skipping.', 'default' => false],
        ],
        'required' => ['data'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'imported' => ['type' => 'integer'],
            'updated' => ['type' => 'integer'],
            'skipped' => ['type' => 'integer'],
            'errors' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => 'nibwp_migration_import_content',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Imports content from the JSON format produced by migration-export-content.\nPass the 'posts' array from the export output as the 'data' input.\nSet update_existing=true to update posts matched by slug; otherwise duplicates are skipped.\nUse status_override to force all imported posts to a specific status (e.g. 'draft').\nUse post_type to override the post type for all items.\nMeta and terms are imported automatically if present in the data.",
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_migration_import_content(array $input): array|WP_Error
{
    $data = $input['data'] ?? [];
    if (!is_array($data) || empty($data)) {
        return new WP_Error('invalid_data', 'The data parameter must be a non-empty array of post objects.');
    }

    $post_type_override = sanitize_key($input['post_type'] ?? '');
    $status_override = sanitize_key($input['status_override'] ?? '');
    $update_existing = !empty($input['update_existing']);

    $imported = 0;
    $updated = 0;
    $skipped = 0;
    $errors = [];

    foreach ($data as $index => $item) {
        $post_type = $post_type_override ?: ($item['post_type'] ?? 'post');
        $slug = sanitize_title($item['post_name'] ?? $item['post_title'] ?? '');
        $status = $status_override ?: ($item['post_status'] ?? 'draft');

        if (empty($item['post_title'])) {
            $errors[] = ['index' => $index, 'error' => 'Missing post_title.'];
            continue;
        }

        // Check for existing post by slug.
        $existing = get_page_by_path($slug, OBJECT, $post_type);

        if ($existing && !$update_existing) {
            $skipped++;
            continue;
        }

        $post_data = [
            'post_title' => sanitize_text_field($item['post_title']),
            'post_name' => $slug,
            'post_content' => $item['post_content'] ?? '',
            'post_excerpt' => $item['post_excerpt'] ?? '',
            'post_status' => $status,
            'post_type' => $post_type,
            'menu_order' => (int) ($item['menu_order'] ?? 0),
            'comment_status' => $item['comment_status'] ?? 'closed',
            'ping_status' => $item['ping_status'] ?? 'closed',
        ];

        if ($existing && $update_existing) {
            $post_data['ID'] = $existing->ID;
            $result = wp_update_post($post_data, true);
            if (is_wp_error($result)) {
                $errors[] = ['index' => $index, 'slug' => $slug, 'error' => $result->get_error_message()];
                continue;
            }
            $post_id = $existing->ID;
            $updated++;
        } else {
            $result = wp_insert_post($post_data, true);
            if (is_wp_error($result)) {
                $errors[] = ['index' => $index, 'slug' => $slug, 'error' => $result->get_error_message()];
                continue;
            }
            $post_id = $result;
            $imported++;
        }

        // Import meta.
        if (!empty($item['meta']) && is_array($item['meta'])) {
            foreach ($item['meta'] as $key => $value) {
                if (str_starts_with($key, '_thumbnail_id')) {
                    continue; // Skip thumbnail ID — it won't match on a new site.
                }
                delete_post_meta($post_id, $key);
                if (is_array($value) && array_is_list($value)) {
                    foreach ($value as $v) {
                        add_post_meta($post_id, $key, $v);
                    }
                } else {
                    update_post_meta($post_id, $key, $value);
                }
            }
        }

        // Import terms.
        if (!empty($item['terms']) && is_array($item['terms'])) {
            foreach ($item['terms'] as $taxonomy => $terms) {
                if (!taxonomy_exists($taxonomy)) {
                    continue;
                }
                $term_ids = [];
                foreach ($terms as $term_data) {
                    $existing_term = get_term_by('slug', $term_data['slug'], $taxonomy);
                    if ($existing_term) {
                        $term_ids[] = (int) $existing_term->term_id;
                    } else {
                        $new_term = wp_insert_term($term_data['name'], $taxonomy, ['slug' => $term_data['slug']]);
                        if (!is_wp_error($new_term)) {
                            $term_ids[] = (int) $new_term['term_id'];
                        }
                    }
                }
                if (!empty($term_ids)) {
                    wp_set_object_terms($post_id, $term_ids, $taxonomy);
                }
            }
        }
    }

    return [
        'success' => true,
        'imported' => $imported,
        'updated' => $updated,
        'skipped' => $skipped,
        'errors' => $errors,
    ];
}

// ═══════════════════════════════════════════════════════════════
// 3. EXPORT OPTIONS — Export WordPress options
// ═══════════════════════════════════════════════════════════════

wp_register_ability('nibwp/migration-export-options', [
    'label' => __('Export WordPress Options', domain: 'nibwp'),
    'description' => __('Exports WordPress options by name or prefix to a portable key-value format for migration between environments.', domain: 'nibwp'),
    'category' => 'migration',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'option_names' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Specific option names to export.', 'default' => []],
            'prefix' => ['type' => 'string', 'description' => 'Export all options with this prefix.', 'default' => ''],
            'exclude_transients' => ['type' => 'boolean', 'description' => 'Exclude transient options.', 'default' => true],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'count' => ['type' => 'integer'],
            'options' => ['type' => 'object', 'description' => 'Key-value map of option names to values.'],
        ],
    ],
    'execute_callback' => 'nibwp_migration_export_options',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Exports WordPress options for migration.\nProvide specific option_names OR a prefix to match multiple options.\nIf both are given, results are merged.\nTransients are excluded by default — set exclude_transients=false to include them.\nOutput feeds directly into migration-import-options.",
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_migration_export_options(array $input): array|WP_Error
{
    global $wpdb;

    $option_names = $input['option_names'] ?? [];
    $prefix = trim((string) ($input['prefix'] ?? ''));
    $exclude_transients = $input['exclude_transients'] ?? true;

    if (empty($option_names) && $prefix === '') {
        return new WP_Error('no_filter', 'Provide either option_names or a prefix to filter options.');
    }

    $options = [];

    // Fetch specific options by name.
    foreach ($option_names as $name) {
        $name = sanitize_key($name);
        $value = get_option($name, null);
        if ($value !== null) {
            $options[$name] = $value;
        }
    }

    // Fetch options by prefix.
    if ($prefix !== '') {
        $like = $wpdb->esc_like($prefix) . '%';
        $sql = $wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
            $like,
        );
        $rows = $wpdb->get_results($sql);
        foreach ($rows as $row) {
            if ($exclude_transients && (str_starts_with($row->option_name, '_transient_') || str_starts_with($row->option_name, '_site_transient_'))) {
                continue;
            }
            $options[$row->option_name] = maybe_unserialize($row->option_value);
        }
    }

    return [
        'success' => true,
        'count' => count($options),
        'options' => $options,
    ];
}

// ═══════════════════════════════════════════════════════════════
// 4. IMPORT OPTIONS — Import WordPress options
// ═══════════════════════════════════════════════════════════════

wp_register_ability('nibwp/migration-import-options', [
    'label' => __('Import WordPress Options', domain: 'nibwp'),
    'description' => __('Imports WordPress options from a key-value map, with support for dry-run preview and overwrite control.', domain: 'nibwp'),
    'category' => 'migration',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'options' => ['type' => 'object', 'description' => 'Key-value pairs of option names and values to import.'],
            'overwrite' => ['type' => 'boolean', 'description' => 'Overwrite existing options.', 'default' => false],
            'dry_run' => ['type' => 'boolean', 'description' => 'Preview without making changes.', 'default' => true],
        ],
        'required' => ['options'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'imported' => ['type' => 'integer'],
            'skipped' => ['type' => 'integer'],
            'details' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => 'nibwp_migration_import_options',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Imports WordPress options from a key-value map.\nALWAYS run with dry_run=true first to preview changes.\nBy default, existing options are NOT overwritten — set overwrite=true to replace them.\nAccepts the 'options' output from migration-export-options directly.",
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_migration_import_options(array $input): array|WP_Error
{
    $options = $input['options'] ?? [];
    if (!is_array($options) || empty($options)) {
        return new WP_Error('invalid_options', 'The options parameter must be a non-empty object.');
    }

    $overwrite = !empty($input['overwrite']);
    $dry_run = $input['dry_run'] ?? true;

    $imported = 0;
    $skipped = 0;
    $details = [];

    foreach ($options as $name => $value) {
        $name = sanitize_key($name);
        $existing = get_option($name);
        $exists = $existing !== false;

        if ($exists && !$overwrite) {
            $skipped++;
            $details[] = ['option' => $name, 'action' => 'skipped', 'reason' => 'exists'];
            continue;
        }

        $action = $exists ? 'overwritten' : 'created';

        if (!$dry_run) {
            update_option($name, $value);
        }

        $imported++;
        $details[] = ['option' => $name, 'action' => $action];
    }

    return [
        'success' => true,
        'dry_run' => $dry_run,
        'imported' => $imported,
        'skipped' => $skipped,
        'details' => $details,
    ];
}

// ═══════════════════════════════════════════════════════════════
// 5. SEARCH & REPLACE URLs — Safe URL replacement in database
// ═══════════════════════════════════════════════════════════════

wp_register_ability('nibwp/migration-search-replace-urls', [
    'label' => __('Search & Replace URLs in Database', domain: 'nibwp'),
    'description' => __('Performs a serialization-safe search and replace of URLs across all major WordPress database tables. Essential for domain migrations.', domain: 'nibwp'),
    'category' => 'migration',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'old_url' => ['type' => 'string', 'description' => 'The URL to search for (e.g. https://old-domain.com).'],
            'new_url' => ['type' => 'string', 'description' => 'The replacement URL (e.g. https://new-domain.com).'],
            'dry_run' => ['type' => 'boolean', 'description' => 'Preview changes without modifying the database.', 'default' => true],
        ],
        'required' => ['old_url', 'new_url'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'dry_run' => ['type' => 'boolean'],
            'old_url' => ['type' => 'string'],
            'new_url' => ['type' => 'string'],
            'tables' => ['type' => 'object', 'description' => 'Rows affected per table.'],
            'total_affected' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => 'nibwp_migration_search_replace_urls',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Searches and replaces URLs across the WordPress database.\nHandles serialized data safely (unserialize → replace → reserialize).\nALWAYS run with dry_run=true first to see how many rows would be affected.\nThen run with dry_run=false to apply.\nCovers: posts, postmeta, options, comments, commentmeta, termmeta, links.\nDo NOT include trailing slashes — they are handled automatically.\nBack up the database before running with dry_run=false.",
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_migration_search_replace_urls(array $input): array|WP_Error
{
    global $wpdb;

    $old_url = untrailingslashit(trim($input['old_url'] ?? ''));
    $new_url = untrailingslashit(trim($input['new_url'] ?? ''));
    $dry_run = $input['dry_run'] ?? true;

    if ($old_url === '' || $new_url === '') {
        return new WP_Error('missing_urls', 'Both old_url and new_url are required.');
    }

    if ($old_url === $new_url) {
        return new WP_Error('same_urls', 'old_url and new_url must be different.');
    }

    // Tables and their text columns to scan.
    $tables = [
        $wpdb->posts => ['post_content', 'post_excerpt', 'post_content_filtered', 'guid'],
        $wpdb->postmeta => ['meta_value'],
        $wpdb->options => ['option_value'],
        $wpdb->comments => ['comment_content', 'comment_author_url'],
        $wpdb->commentmeta => ['meta_value'],
        $wpdb->termmeta => ['meta_value'],
        $wpdb->links => ['link_url', 'link_description', 'link_notes', 'link_image'],
    ];

    $primary_keys = [
        $wpdb->posts => 'ID',
        $wpdb->postmeta => 'meta_id',
        $wpdb->options => 'option_id',
        $wpdb->comments => 'comment_ID',
        $wpdb->commentmeta => 'meta_id',
        $wpdb->termmeta => 'meta_id',
        $wpdb->links => 'link_id',
    ];

    $results = [];
    $total = 0;

    foreach ($tables as $table => $columns) {
        $pk = $primary_keys[$table];
        $affected = 0;

        foreach ($columns as $column) {
            // Find rows containing the old URL.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT `{$pk}`, `{$column}` FROM `{$table}` WHERE `{$column}` LIKE %s",
                    '%' . $wpdb->esc_like($old_url) . '%',
                ),
            );

            foreach ($rows as $row) {
                $old_value = $row->$column;
                $new_value = nibwp_migration_sr_replace($old_value, $old_url, $new_url);

                if ($old_value !== $new_value) {
                    $affected++;
                    if (!$dry_run) {
                        $wpdb->update(
                            $table,
                            [$column => $new_value],
                            [$pk => $row->$pk],
                        );
                    }
                }
            }
        }

        $results[str_replace($wpdb->prefix, '', $table)] = $affected;
        $total += $affected;
    }

    return [
        'success' => true,
        'dry_run' => $dry_run,
        'old_url' => $old_url,
        'new_url' => $new_url,
        'tables' => $results,
        'total_affected' => $total,
    ];
}

/**
 * Serialization-safe string replacement.
 * Handles both plain strings and PHP serialized data.
 */
function nibwp_migration_sr_replace(string $value, string $search, string $replace): string
{
    // Try to unserialize — if it works, do recursive replacement.
    $unserialized = @unserialize($value);
    if ($unserialized !== false || $value === 'b:0;') {
        $replaced = nibwp_migration_sr_replace_recursive($unserialized, $search, $replace);
        return serialize($replaced);
    }

    // Plain string replacement.
    return str_replace($search, $replace, $value);
}

/**
 * Recursively replace strings within arrays and objects.
 */
function nibwp_migration_sr_replace_recursive(mixed $data, string $search, string $replace): mixed
{
    if (is_string($data)) {
        return str_replace($search, $replace, $data);
    }

    if (is_array($data)) {
        return array_map(fn($item) => nibwp_migration_sr_replace_recursive($item, $search, $replace), $data);
    }

    if (is_object($data)) {
        foreach (get_object_vars($data) as $key => $val) {
            $data->$key = nibwp_migration_sr_replace_recursive($val, $search, $replace);
        }
        return $data;
    }

    return $data;
}

// ═══════════════════════════════════════════════════════════════
// 6. CLONE POST — Deep clone a post with all relationships
// ═══════════════════════════════════════════════════════════════

wp_register_ability('nibwp/migration-clone-post', [
    'label' => __('Deep Clone a Post', domain: 'nibwp'),
    'description' => __('Creates a complete duplicate of a post including all meta fields, taxonomy terms, and featured image. Optionally clones child posts.', domain: 'nibwp'),
    'category' => 'migration',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'description' => 'ID of the post to clone.'],
            'new_title' => ['type' => 'string', 'description' => 'Title for the cloned post (default: original title + " (Copy)").', 'default' => ''],
            'new_status' => ['type' => 'string', 'description' => 'Status for the cloned post.', 'default' => 'draft'],
            'clone_children' => ['type' => 'boolean', 'description' => 'Also clone child posts (hierarchical).', 'default' => false],
        ],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'new_post_id' => ['type' => 'integer'],
            'cloned_meta_count' => ['type' => 'integer'],
            'cloned_terms' => ['type' => 'object'],
            'cloned_children' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => 'nibwp_migration_clone_post',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Creates a complete deep clone of a post.\nClones: post data, all meta fields, taxonomy terms, featured image reference.\nThe clone is created as 'draft' by default.\nSet clone_children=true to also clone hierarchical child posts.\nUseful for creating templates, duplicating landing pages, or testing changes on a copy.",
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_migration_clone_post(array $input): array|WP_Error
{
    $post_id = (int) ($input['post_id'] ?? 0);
    $original = get_post($post_id);

    if (!$original) {
        return new WP_Error('post_not_found', "Post #{$post_id} does not exist.");
    }

    $new_title = trim((string) ($input['new_title'] ?? ''));
    if ($new_title === '') {
        $new_title = $original->post_title . ' (Copy)';
    }
    $new_status = sanitize_key($input['new_status'] ?? 'draft');
    $clone_children = !empty($input['clone_children']);

    // Clone the post.
    $new_post_data = [
        'post_title' => $new_title,
        'post_content' => $original->post_content,
        'post_excerpt' => $original->post_excerpt,
        'post_status' => $new_status,
        'post_type' => $original->post_type,
        'post_parent' => $original->post_parent,
        'menu_order' => $original->menu_order,
        'comment_status' => $original->comment_status,
        'ping_status' => $original->ping_status,
        'post_password' => $original->post_password,
    ];

    $new_post_id = wp_insert_post($new_post_data, true);
    if (is_wp_error($new_post_id)) {
        return $new_post_id;
    }

    // Clone all meta.
    $meta = get_post_meta($post_id);
    $meta_count = 0;
    foreach ($meta as $key => $values) {
        foreach ($values as $value) {
            add_post_meta($new_post_id, $key, maybe_unserialize($value));
            $meta_count++;
        }
    }

    // Clone term relationships.
    $cloned_terms = [];
    $taxonomies = get_object_taxonomies($original->post_type);
    foreach ($taxonomies as $taxonomy) {
        $terms = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
        if (!is_wp_error($terms) && !empty($terms)) {
            wp_set_object_terms($new_post_id, $terms, $taxonomy);
            $cloned_terms[$taxonomy] = count($terms);
        }
    }

    // Clone children if requested.
    $cloned_children = [];
    if ($clone_children) {
        $children = get_children([
            'post_parent' => $post_id,
            'post_type' => $original->post_type,
            'posts_per_page' => -1,
        ]);
        foreach ($children as $child) {
            $child_result = nibwp_migration_clone_post([
                'post_id' => $child->ID,
                'new_status' => $new_status,
            ]);
            if (!is_wp_error($child_result)) {
                // Re-parent the cloned child to the new parent.
                wp_update_post(['ID' => $child_result['new_post_id'], 'post_parent' => $new_post_id]);
                $cloned_children[] = [
                    'original_id' => $child->ID,
                    'new_id' => $child_result['new_post_id'],
                ];
            }
        }
    }

    return [
        'success' => true,
        'new_post_id' => $new_post_id,
        'cloned_meta_count' => $meta_count,
        'cloned_terms' => $cloned_terms,
        'cloned_children' => $cloned_children,
    ];
}

// ═══════════════════════════════════════════════════════════════
// 7. EXPORT MENUS — Export navigation menus
// ═══════════════════════════════════════════════════════════════

wp_register_ability('nibwp/migration-export-menus', [
    'label' => __('Export Navigation Menus', domain: 'nibwp'),
    'description' => __('Exports WordPress navigation menus with all items, parent relationships, classes, and URLs to a portable format.', domain: 'nibwp'),
    'category' => 'migration',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'menu_id' => ['type' => 'integer', 'description' => 'Specific menu ID to export. Omit to export all menus.', 'default' => 0],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'count' => ['type' => 'integer'],
            'menus' => ['type' => 'array', 'description' => 'Array of menu objects with items.'],
        ],
    ],
    'execute_callback' => 'nibwp_migration_export_menus',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Exports navigation menus to a portable format.\nOmit menu_id to export all registered menus.\nIncludes full item hierarchy, CSS classes, URLs, and menu locations.\nOutput feeds directly into migration-import-menus.",
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_migration_export_menus(array $input): array|WP_Error
{
    $menu_id = (int) ($input['menu_id'] ?? 0);

    if ($menu_id > 0) {
        $menu_obj = wp_get_nav_menu_object($menu_id);
        if (!$menu_obj) {
            return new WP_Error('menu_not_found', "Menu #{$menu_id} does not exist.");
        }
        $nav_menus = [$menu_obj];
    } else {
        $nav_menus = wp_get_nav_menus();
    }

    if (empty($nav_menus)) {
        return ['success' => true, 'count' => 0, 'menus' => []];
    }

    // Get menu locations.
    $locations = get_nav_menu_locations();
    $location_map = [];
    foreach ($locations as $location => $assigned_menu_id) {
        $location_map[$assigned_menu_id][] = $location;
    }

    $menus = [];
    foreach ($nav_menus as $menu) {
        $items = wp_get_nav_menu_items($menu->term_id, ['update_post_term_cache' => false]);
        $exported_items = [];

        if (is_array($items)) {
            foreach ($items as $item) {
                $exported_items[] = [
                    'title' => $item->title,
                    'url' => $item->url,
                    'type' => $item->type,
                    'object' => $item->object,
                    'object_id' => (int) $item->object_id,
                    'menu_item_parent' => (int) $item->menu_item_parent,
                    'position' => (int) $item->menu_order,
                    'target' => $item->target,
                    'classes' => array_filter($item->classes),
                    'xfn' => $item->xfn,
                    'description' => $item->description,
                    'attr_title' => $item->attr_title,
                ];
            }
        }

        $menus[] = [
            'name' => $menu->name,
            'slug' => $menu->slug,
            'description' => $menu->description,
            'locations' => $location_map[$menu->term_id] ?? [],
            'items' => $exported_items,
        ];
    }

    return [
        'success' => true,
        'count' => count($menus),
        'menus' => $menus,
    ];
}

// ═══════════════════════════════════════════════════════════════
// 8. IMPORT MENUS — Import navigation menus
// ═══════════════════════════════════════════════════════════════

wp_register_ability('nibwp/migration-import-menus', [
    'label' => __('Import Navigation Menus', domain: 'nibwp'),
    'description' => __('Imports navigation menus from the export format, creating menus and menu items with proper parent-child relationships.', domain: 'nibwp'),
    'category' => 'migration',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'menus' => ['type' => 'array', 'description' => 'Array of menu objects from migration-export-menus.'],
            'overwrite' => ['type' => 'boolean', 'description' => 'Delete existing menus with the same slug before importing.', 'default' => false],
        ],
        'required' => ['menus'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'imported' => ['type' => 'integer'],
            'items_imported' => ['type' => 'integer'],
            'errors' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => 'nibwp_migration_import_menus',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Imports navigation menus from the format produced by migration-export-menus.\nPass the 'menus' array from the export output.\nSet overwrite=true to delete existing menus with the same slug before importing.\nMenu item parent relationships are preserved.\nMenu locations are restored if the theme supports them.",
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_migration_import_menus(array $input): array|WP_Error
{
    $menus = $input['menus'] ?? [];
    if (!is_array($menus) || empty($menus)) {
        return new WP_Error('invalid_menus', 'The menus parameter must be a non-empty array.');
    }

    $overwrite = !empty($input['overwrite']);
    $imported = 0;
    $items_imported = 0;
    $errors = [];
    $location_assignments = get_nav_menu_locations();

    foreach ($menus as $menu_data) {
        $menu_name = $menu_data['name'] ?? '';
        $menu_slug = $menu_data['slug'] ?? sanitize_title($menu_name);

        if (empty($menu_name)) {
            $errors[] = 'Menu with empty name skipped.';
            continue;
        }

        // Check for existing menu.
        $existing = wp_get_nav_menu_object($menu_slug);

        if ($existing && $overwrite) {
            wp_delete_nav_menu($existing->term_id);
            $existing = false;
        }

        if ($existing && !$overwrite) {
            $errors[] = "Menu '{$menu_name}' already exists. Set overwrite=true to replace.";
            continue;
        }

        // Create the menu.
        $menu_id = wp_create_nav_menu($menu_name);
        if (is_wp_error($menu_id)) {
            $errors[] = "Failed to create menu '{$menu_name}': " . $menu_id->get_error_message();
            continue;
        }

        $imported++;

        // Import menu items, preserving parent relationships.
        // We need to map old parent positions to new item IDs.
        $position_to_id = []; // position => new menu item ID

        // Sort items by position to ensure parents are created before children.
        $items = $menu_data['items'] ?? [];
        usort($items, fn($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

        foreach ($items as $item_data) {
            $menu_item_data = [
                'menu-item-title' => $item_data['title'] ?? '',
                'menu-item-url' => $item_data['url'] ?? '',
                'menu-item-type' => $item_data['type'] ?? 'custom',
                'menu-item-object' => $item_data['object'] ?? '',
                'menu-item-object-id' => $item_data['object_id'] ?? 0,
                'menu-item-position' => $item_data['position'] ?? 0,
                'menu-item-target' => $item_data['target'] ?? '',
                'menu-item-classes' => implode(' ', $item_data['classes'] ?? []),
                'menu-item-xfn' => $item_data['xfn'] ?? '',
                'menu-item-description' => $item_data['description'] ?? '',
                'menu-item-attr-title' => $item_data['attr_title'] ?? '',
                'menu-item-status' => 'publish',
            ];

            // Resolve parent.
            $parent_position = (int) ($item_data['menu_item_parent'] ?? 0);
            if ($parent_position > 0 && isset($position_to_id[$parent_position])) {
                $menu_item_data['menu-item-parent-id'] = $position_to_id[$parent_position];
            }

            $new_item_id = wp_update_nav_menu_item($menu_id, 0, $menu_item_data);
            if (!is_wp_error($new_item_id)) {
                $position_to_id[$item_data['position'] ?? 0] = $new_item_id;
                // Also map by the original menu_item_parent ID for child lookups.
                if (!empty($item_data['object_id'])) {
                    $position_to_id[$item_data['object_id']] = $new_item_id;
                }
                $items_imported++;
            } else {
                $errors[] = "Failed to import item '{$item_data['title']}': " . $new_item_id->get_error_message();
            }
        }

        // Assign menu locations.
        if (!empty($menu_data['locations'])) {
            foreach ($menu_data['locations'] as $location) {
                $location_assignments[$location] = $menu_id;
            }
        }
    }

    // Save location assignments.
    set_theme_mod('nav_menu_locations', $location_assignments);

    return [
        'success' => true,
        'imported' => $imported,
        'items_imported' => $items_imported,
        'errors' => $errors,
    ];
}
