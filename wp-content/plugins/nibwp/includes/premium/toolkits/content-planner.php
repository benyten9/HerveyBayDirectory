<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

// =============================================================================
// Ability: nibwp/content-schedule-post
// =============================================================================

wp_register_ability('nibwp/content-schedule-post', [
    'label' => __('Content – Schedule Post', domain: 'nibwp'),
    'description' => __(
        'Schedule a new or existing post for future publication at a specific date and time.',
        domain: 'nibwp',
    ),
    'category' => 'content',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => [
                'type' => 'integer',
                'description' => 'Optional: existing post ID to schedule. If omitted, creates a new post.',
            ],
            'title' => [
                'type' => 'string',
                'description' => 'Post title (required when creating new).',
            ],
            'content' => [
                'type' => 'string',
                'description' => 'Post content (HTML).',
            ],
            'excerpt' => [
                'type' => 'string',
                'description' => 'Post excerpt.',
            ],
            'post_type' => [
                'type' => 'string',
                'description' => 'Post type. Default "post".',
            ],
            'publish_date' => [
                'type' => 'string',
                'description' => 'ISO 8601 date for scheduled publication. Must be in the future.',
            ],
            'categories' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
                'description' => 'Array of category IDs.',
            ],
            'tags' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Array of tag names.',
            ],
            'author' => [
                'type' => 'integer',
                'description' => 'Author user ID.',
            ],
            'meta' => [
                'type' => 'object',
                'description' => 'Key-value pairs of post meta to set.',
            ],
        ],
        'required' => ['publish_date'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'scheduled_date' => ['type' => 'string'],
            'edit_url' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => 'nibwp_content_schedule_post',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Schedule a post for future publication.',
                '',
                'TWO MODES:',
                '1. Create new scheduled post: provide title, content, publish_date.',
                '2. Schedule existing post: provide post_id and publish_date.',
                '',
                'publish_date must be ISO 8601 format (e.g. "2026-06-15T09:00:00").',
                'The date MUST be in the future; past dates are rejected.',
                '',
                'OPTIONAL FIELDS (for new posts):',
                '- post_type: defaults to "post".',
                '- categories: array of category term IDs.',
                '- tags: array of tag names (created if they do not exist).',
                '- author: user ID (defaults to current user).',
                '- excerpt: manual excerpt.',
                '- meta: object of custom field key-value pairs.',
                '',
                'Returns the post_id, scheduled_date in site timezone, and admin edit URL.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_content_schedule_post(array $input): array|WP_Error
{
    $publish_date = $input['publish_date'] ?? '';
    if (empty($publish_date)) {
        return new WP_Error('missing_date', 'publish_date is required.');
    }

    $timestamp = strtotime($publish_date);
    if ($timestamp === false) {
        return new WP_Error('invalid_date', 'publish_date must be a valid ISO 8601 date string.');
    }

    $gmt_timestamp = get_gmt_from_date(date('Y-m-d H:i:s', $timestamp), 'U');
    if ((int) $gmt_timestamp <= time()) {
        return new WP_Error('date_in_past', 'publish_date must be in the future.');
    }

    $post_date = date('Y-m-d H:i:s', $timestamp);
    $post_date_gmt = get_gmt_from_date($post_date);

    $post_id = isset($input['post_id']) ? (int) $input['post_id'] : 0;

    if ($post_id > 0) {
        // Schedule existing post.
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('post_not_found', sprintf('Post %d not found.', $post_id));
        }

        $update_args = [
            'ID' => $post_id,
            'post_status' => 'future',
            'post_date' => $post_date,
            'post_date_gmt' => $post_date_gmt,
        ];

        if (isset($input['title'])) {
            $update_args['post_title'] = sanitize_text_field($input['title']);
        }
        if (isset($input['content'])) {
            $update_args['post_content'] = wp_kses_post($input['content']);
        }
        if (isset($input['excerpt'])) {
            $update_args['post_excerpt'] = sanitize_textarea_field($input['excerpt']);
        }

        $result = wp_update_post($update_args, true);
        if (is_wp_error($result)) {
            return $result;
        }
    } else {
        // Create new scheduled post.
        if (empty($input['title'])) {
            return new WP_Error('missing_title', 'title is required when creating a new post.');
        }

        $post_type = sanitize_text_field($input['post_type'] ?? 'post');
        if (!post_type_exists($post_type)) {
            return new WP_Error('invalid_post_type', sprintf('Post type "%s" does not exist.', $post_type));
        }

        $insert_args = [
            'post_title' => sanitize_text_field($input['title']),
            'post_content' => wp_kses_post($input['content'] ?? ''),
            'post_excerpt' => sanitize_textarea_field($input['excerpt'] ?? ''),
            'post_status' => 'future',
            'post_type' => $post_type,
            'post_date' => $post_date,
            'post_date_gmt' => $post_date_gmt,
        ];

        if (isset($input['author'])) {
            $author = get_userdata((int) $input['author']);
            if (!$author) {
                return new WP_Error('invalid_author', sprintf('User %d not found.', $input['author']));
            }
            $insert_args['post_author'] = $author->ID;
        }

        $post_id = wp_insert_post($insert_args, true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // Set categories.
        if (!empty($input['categories']) && is_array($input['categories'])) {
            wp_set_post_categories($post_id, array_map('absint', $input['categories']));
        }

        // Set tags.
        if (!empty($input['tags']) && is_array($input['tags'])) {
            wp_set_post_tags($post_id, array_map('sanitize_text_field', $input['tags']));
        }
    }

    // Set meta.
    if (!empty($input['meta']) && is_array($input['meta'])) {
        foreach ($input['meta'] as $key => $value) {
            update_post_meta($post_id, sanitize_key($key), sanitize_text_field((string) $value));
        }
    }

    return [
        'post_id' => $post_id,
        'scheduled_date' => $post_date,
        'edit_url' => get_edit_post_link($post_id, 'raw'),
    ];
}

// =============================================================================
// Ability: nibwp/content-list-scheduled
// =============================================================================

wp_register_ability('nibwp/content-list-scheduled', [
    'label' => __('Content – List Scheduled Posts', domain: 'nibwp'),
    'description' => __(
        'List all posts scheduled for future publication.',
        domain: 'nibwp',
    ),
    'category' => 'content',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_type' => [
                'type' => 'string',
                'description' => 'Post type to filter. Default "any".',
            ],
            'per_page' => [
                'type' => 'integer',
                'description' => 'Number of posts to return. Default 50.',
            ],
            'orderby' => [
                'type' => 'string',
                'description' => 'Order by field. Default "date".',
            ],
        ],
        'required' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'total' => ['type' => 'integer'],
            'posts' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'title' => ['type' => 'string'],
                        'scheduled_date' => ['type' => 'string'],
                        'post_type' => ['type' => 'string'],
                        'author' => ['type' => 'string'],
                        'categories' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
            ],
        ],
    ],
    'execute_callback' => 'nibwp_content_list_scheduled',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'List all scheduled (future) posts.',
                '',
                'Returns posts with status "future" ordered by scheduled date.',
                'Use post_type to filter (default "any" shows all post types).',
                'per_page controls how many results (default 50, max 100).',
                '',
                'Each post includes: id, title, scheduled_date, post_type, author name, categories.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_content_list_scheduled(array $input): array|WP_Error
{
    $post_type = sanitize_text_field($input['post_type'] ?? 'any');
    $per_page = min(max((int) ($input['per_page'] ?? 50), 1), 100);
    $orderby = sanitize_text_field($input['orderby'] ?? 'date');

    $query = new WP_Query([
        'post_type' => $post_type,
        'post_status' => 'future',
        'posts_per_page' => $per_page,
        'orderby' => $orderby,
        'order' => 'ASC',
    ]);

    $posts = [];
    foreach ($query->posts as $post) {
        $author = get_userdata($post->post_author);
        $cats = wp_get_post_categories($post->ID, ['fields' => 'names']);

        $posts[] = [
            'id' => $post->ID,
            'title' => get_the_title($post),
            'scheduled_date' => $post->post_date,
            'post_type' => $post->post_type,
            'author' => $author ? $author->display_name : '',
            'categories' => is_array($cats) ? $cats : [],
        ];
    }

    return [
        'total' => $query->found_posts,
        'posts' => $posts,
    ];
}

// =============================================================================
// Ability: nibwp/content-reschedule
// =============================================================================

wp_register_ability('nibwp/content-reschedule', [
    'label' => __('Content – Reschedule Post', domain: 'nibwp'),
    'description' => __(
        'Change the scheduled publication date of a future post.',
        domain: 'nibwp',
    ),
    'category' => 'content',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => [
                'type' => 'integer',
                'description' => 'The post ID to reschedule.',
            ],
            'new_date' => [
                'type' => 'string',
                'description' => 'New publication date in ISO 8601 format.',
            ],
        ],
        'required' => ['post_id', 'new_date'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'old_date' => ['type' => 'string'],
            'new_date' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => 'nibwp_content_reschedule',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Change the scheduled date of a future post.',
                '',
                'The post MUST have status "future". Published, draft, or other statuses are rejected.',
                'new_date must be in ISO 8601 format and in the future.',
                '',
                'Returns old_date and new_date for confirmation.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_content_reschedule(array $input): array|WP_Error
{
    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0) {
        return new WP_Error('invalid_post_id', 'post_id must be a positive integer.');
    }

    $post = get_post($post_id);
    if (!$post) {
        return new WP_Error('post_not_found', sprintf('Post %d not found.', $post_id));
    }

    if ($post->post_status !== 'future') {
        return new WP_Error('not_scheduled', sprintf('Post %d has status "%s", not "future". Only scheduled posts can be rescheduled.', $post_id, $post->post_status));
    }

    $new_date_raw = $input['new_date'] ?? '';
    $timestamp = strtotime($new_date_raw);
    if ($timestamp === false) {
        return new WP_Error('invalid_date', 'new_date must be a valid ISO 8601 date string.');
    }

    $gmt_timestamp = get_gmt_from_date(date('Y-m-d H:i:s', $timestamp), 'U');
    if ((int) $gmt_timestamp <= time()) {
        return new WP_Error('date_in_past', 'new_date must be in the future.');
    }

    $old_date = $post->post_date;
    $new_date = date('Y-m-d H:i:s', $timestamp);
    $new_date_gmt = get_gmt_from_date($new_date);

    $result = wp_update_post([
        'ID' => $post_id,
        'post_date' => $new_date,
        'post_date_gmt' => $new_date_gmt,
        'post_status' => 'future',
    ], true);

    if (is_wp_error($result)) {
        return $result;
    }

    return [
        'post_id' => $post_id,
        'old_date' => $old_date,
        'new_date' => $new_date,
    ];
}

// =============================================================================
// Ability: nibwp/content-calendar
// =============================================================================

wp_register_ability('nibwp/content-calendar', [
    'label' => __('Content – Editorial Calendar', domain: 'nibwp'),
    'description' => __(
        'Get an editorial calendar view with posts grouped by date.',
        domain: 'nibwp',
    ),
    'category' => 'content',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'start_date' => [
                'type' => 'string',
                'description' => 'Start date in YYYY-MM-DD format.',
            ],
            'end_date' => [
                'type' => 'string',
                'description' => 'End date in YYYY-MM-DD format.',
            ],
            'post_types' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Post types to include. Default ["post"].',
            ],
            'statuses' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Post statuses to include. Default ["publish","future","draft","pending"].',
            ],
        ],
        'required' => ['start_date', 'end_date'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'start_date' => ['type' => 'string'],
            'end_date' => ['type' => 'string'],
            'total_posts' => ['type' => 'integer'],
            'dates' => [
                'type' => 'object',
                'description' => 'Posts grouped by date key (YYYY-MM-DD).',
            ],
        ],
    ],
    'execute_callback' => 'nibwp_content_calendar',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Get an editorial calendar view of posts grouped by date.',
                '',
                'Returns a "dates" object where each key is a YYYY-MM-DD date and the value',
                'is an array of posts scheduled/published on that date.',
                '',
                'REQUIRED: start_date and end_date in YYYY-MM-DD format.',
                'Max range is 90 days to keep responses manageable.',
                '',
                'OPTIONAL:',
                '- post_types: array of post types (default ["post"]).',
                '- statuses: array of statuses (default ["publish","future","draft","pending"]).',
                '',
                'Each post entry includes: id, title, status, type, author, time.',
                'Dates with no posts are included as empty arrays.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_content_calendar(array $input): array|WP_Error
{
    $start_date = $input['start_date'] ?? '';
    $end_date = $input['end_date'] ?? '';

    $start_ts = strtotime($start_date);
    $end_ts = strtotime($end_date);

    if ($start_ts === false || $end_ts === false) {
        return new WP_Error('invalid_dates', 'start_date and end_date must be valid YYYY-MM-DD dates.');
    }

    if ($end_ts < $start_ts) {
        return new WP_Error('invalid_range', 'end_date must be after start_date.');
    }

    $day_count = (int) (($end_ts - $start_ts) / 86400) + 1;
    if ($day_count > 90) {
        return new WP_Error('range_too_large', 'Date range must not exceed 90 days.');
    }

    $post_types = !empty($input['post_types']) && is_array($input['post_types'])
        ? array_map('sanitize_text_field', $input['post_types'])
        : ['post'];

    $statuses = !empty($input['statuses']) && is_array($input['statuses'])
        ? array_map('sanitize_text_field', $input['statuses'])
        : ['publish', 'future', 'draft', 'pending'];

    // Initialize all dates in range.
    $dates = [];
    $current = $start_ts;
    while ($current <= $end_ts) {
        $dates[date('Y-m-d', $current)] = [];
        $current += 86400;
    }

    $query = new WP_Query([
        'post_type' => $post_types,
        'post_status' => $statuses,
        'posts_per_page' => 500,
        'date_query' => [
            [
                'after' => date('Y-m-d', $start_ts),
                'before' => date('Y-m-d', $end_ts),
                'inclusive' => true,
            ],
        ],
        'orderby' => 'date',
        'order' => 'ASC',
    ]);

    $total = 0;
    foreach ($query->posts as $post) {
        $date_key = date('Y-m-d', strtotime($post->post_date));
        if (!isset($dates[$date_key])) {
            $dates[$date_key] = [];
        }
        $author = get_userdata($post->post_author);
        $dates[$date_key][] = [
            'id' => $post->ID,
            'title' => get_the_title($post),
            'status' => $post->post_status,
            'type' => $post->post_type,
            'author' => $author ? $author->display_name : '',
            'time' => date('H:i', strtotime($post->post_date)),
        ];
        $total++;
    }

    return [
        'start_date' => $start_date,
        'end_date' => $end_date,
        'total_posts' => $total,
        'dates' => $dates,
    ];
}

// =============================================================================
// Ability: nibwp/content-bulk-schedule
// =============================================================================

wp_register_ability('nibwp/content-bulk-schedule', [
    'label' => __('Content – Bulk Schedule', domain: 'nibwp'),
    'description' => __(
        'Schedule multiple posts at once with automatic interval spacing.',
        domain: 'nibwp',
    ),
    'category' => 'content',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'posts' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                        'excerpt' => ['type' => 'string'],
                        'categories' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['title'],
                ],
                'description' => 'Array of post data objects.',
            ],
            'start_date' => [
                'type' => 'string',
                'description' => 'ISO 8601 date for the first post.',
            ],
            'interval_hours' => [
                'type' => 'integer',
                'description' => 'Hours between each post. Default 24.',
            ],
            'post_type' => [
                'type' => 'string',
                'description' => 'Post type for all posts. Default "post".',
            ],
            'status' => [
                'type' => 'string',
                'description' => 'Post status. Default "future".',
            ],
        ],
        'required' => ['posts', 'start_date'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'created_count' => ['type' => 'integer'],
            'created' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'post_id' => ['type' => 'integer'],
                        'title' => ['type' => 'string'],
                        'scheduled_date' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ],
    'execute_callback' => 'nibwp_content_bulk_schedule',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Schedule multiple posts in one call with automatic date spacing.',
                '',
                'REQUIRED:',
                '- posts: array of objects, each with at minimum a "title".',
                '- start_date: ISO 8601 date for the first post (must be in the future).',
                '',
                'OPTIONAL:',
                '- interval_hours: gap between each post (default 24 = one per day).',
                '- post_type: defaults to "post".',
                '- status: defaults to "future". Set to "draft" to create drafts with dates.',
                '',
                'Each post object can include: title, content, excerpt, categories (int IDs), tags (strings).',
                '',
                'EXAMPLE: Schedule 5 posts starting next Monday, one per day:',
                '  posts: [{title: "Post 1"}, {title: "Post 2"}, ...],',
                '  start_date: "2026-05-25T09:00:00",',
                '  interval_hours: 24',
                '',
                'Maximum 50 posts per call.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_content_bulk_schedule(array $input): array|WP_Error
{
    $posts_data = $input['posts'] ?? [];
    if (empty($posts_data) || !is_array($posts_data)) {
        return new WP_Error('missing_posts', 'posts array is required and must not be empty.');
    }

    if (count($posts_data) > 50) {
        return new WP_Error('too_many_posts', 'Maximum 50 posts per call.');
    }

    $start_date = $input['start_date'] ?? '';
    $start_ts = strtotime($start_date);
    if ($start_ts === false) {
        return new WP_Error('invalid_start_date', 'start_date must be a valid ISO 8601 date.');
    }

    $interval_hours = max((int) ($input['interval_hours'] ?? 24), 1);
    $post_type = sanitize_text_field($input['post_type'] ?? 'post');
    $status = sanitize_text_field($input['status'] ?? 'future');

    if (!post_type_exists($post_type)) {
        return new WP_Error('invalid_post_type', sprintf('Post type "%s" does not exist.', $post_type));
    }

    $allowed_statuses = ['future', 'draft', 'pending'];
    if (!in_array($status, $allowed_statuses, true)) {
        return new WP_Error('invalid_status', sprintf('Status must be one of: %s.', implode(', ', $allowed_statuses)));
    }

    $created = [];
    $current_ts = $start_ts;

    foreach ($posts_data as $idx => $post_data) {
        if (empty($post_data['title'])) {
            return new WP_Error('missing_title', sprintf('Post at index %d is missing a title.', $idx));
        }

        $post_date = date('Y-m-d H:i:s', $current_ts);
        $post_date_gmt = get_gmt_from_date($post_date);

        $insert_args = [
            'post_title' => sanitize_text_field($post_data['title']),
            'post_content' => wp_kses_post($post_data['content'] ?? ''),
            'post_excerpt' => sanitize_textarea_field($post_data['excerpt'] ?? ''),
            'post_status' => $status,
            'post_type' => $post_type,
            'post_date' => $post_date,
            'post_date_gmt' => $post_date_gmt,
        ];

        $new_post_id = wp_insert_post($insert_args, true);
        if (is_wp_error($new_post_id)) {
            return new WP_Error(
                'insert_failed',
                sprintf('Failed to create post "%s": %s', $post_data['title'], $new_post_id->get_error_message())
            );
        }

        if (!empty($post_data['categories']) && is_array($post_data['categories'])) {
            wp_set_post_categories($new_post_id, array_map('absint', $post_data['categories']));
        }

        if (!empty($post_data['tags']) && is_array($post_data['tags'])) {
            wp_set_post_tags($new_post_id, array_map('sanitize_text_field', $post_data['tags']));
        }

        $created[] = [
            'post_id' => $new_post_id,
            'title' => sanitize_text_field($post_data['title']),
            'scheduled_date' => $post_date,
        ];

        $current_ts += $interval_hours * 3600;
    }

    return [
        'created_count' => count($created),
        'created' => $created,
    ];
}

// =============================================================================
// Ability: nibwp/content-draft-manager
// =============================================================================

wp_register_ability('nibwp/content-draft-manager', [
    'label' => __('Content – Draft Manager', domain: 'nibwp'),
    'description' => __(
        'Manage drafts with editorial notes, priority levels, and conversion to scheduled posts.',
        domain: 'nibwp',
    ),
    'category' => 'content',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list', 'add_note', 'set_priority', 'convert_to_scheduled'],
                'description' => 'Action to perform.',
            ],
            'post_id' => [
                'type' => 'integer',
                'description' => 'Post ID (required for all actions except list).',
            ],
            'note' => [
                'type' => 'string',
                'description' => 'Editorial note text (for add_note action).',
            ],
            'priority' => [
                'type' => 'string',
                'enum' => ['low', 'medium', 'high', 'urgent'],
                'description' => 'Priority level (for set_priority action).',
            ],
            'publish_date' => [
                'type' => 'string',
                'description' => 'ISO 8601 publication date (for convert_to_scheduled action).',
            ],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'drafts' => ['type' => 'array'],
            'post' => ['type' => 'object'],
            'message' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => 'nibwp_content_draft_manager',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage draft posts with editorial workflow features.',
                '',
                'ACTION = "list":',
                '- Returns all drafts with their notes and priority levels.',
                '- Sorted by priority (urgent first) then date.',
                '',
                'ACTION = "add_note":',
                '- Requires post_id and note.',
                '- Appends a timestamped note to _nibwp_editorial_note meta.',
                '- Use for editorial feedback, revision requests, or status updates.',
                '',
                'ACTION = "set_priority":',
                '- Requires post_id and priority (low/medium/high/urgent).',
                '- Stores in _nibwp_priority meta.',
                '',
                'ACTION = "convert_to_scheduled":',
                '- Requires post_id and publish_date (ISO 8601, must be future).',
                '- Changes draft status to "future" and sets the publication date.',
                '',
                'Notes are stored as a JSON array in _nibwp_editorial_note meta,',
                'each with timestamp and text.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_content_draft_manager(array $input): array|WP_Error
{
    $action = $input['action'] ?? '';

    if ($action === 'list') {
        $query = new WP_Query([
            'post_type' => 'any',
            'post_status' => 'draft',
            'posts_per_page' => 100,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $priority_order = ['urgent' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, '' => 4];
        $drafts = [];

        foreach ($query->posts as $post) {
            $author = get_userdata($post->post_author);
            $priority = (string) get_post_meta($post->ID, '_nibwp_priority', true);
            $notes_raw = get_post_meta($post->ID, '_nibwp_editorial_note', true);
            $notes = is_array($notes_raw) ? $notes_raw : [];

            $drafts[] = [
                'id' => $post->ID,
                'title' => get_the_title($post),
                'date' => $post->post_date,
                'modified' => $post->post_modified,
                'author' => $author ? $author->display_name : '',
                'post_type' => $post->post_type,
                'priority' => $priority ?: 'none',
                'notes' => $notes,
                'word_count' => str_word_count(wp_strip_all_tags($post->post_content)),
                '_priority_sort' => $priority_order[$priority] ?? 4,
            ];
        }

        // Sort by priority then date.
        usort($drafts, function ($a, $b) {
            $p = $a['_priority_sort'] <=> $b['_priority_sort'];
            if ($p !== 0) {
                return $p;
            }
            return strcmp($b['date'], $a['date']);
        });

        // Remove internal sort key.
        $drafts = array_map(function ($d) {
            unset($d['_priority_sort']);
            return $d;
        }, $drafts);

        return [
            'drafts' => $drafts,
            'post' => null,
            'message' => sprintf('%d draft(s) found.', count($drafts)),
        ];
    }

    // All other actions need post_id.
    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0) {
        return new WP_Error('invalid_post_id', 'post_id is required for this action.');
    }

    $post = get_post($post_id);
    if (!$post) {
        return new WP_Error('post_not_found', sprintf('Post %d not found.', $post_id));
    }

    switch ($action) {
        case 'add_note':
            $note_text = $input['note'] ?? '';
            if (empty(trim($note_text))) {
                return new WP_Error('empty_note', 'note must not be empty.');
            }

            $notes = get_post_meta($post_id, '_nibwp_editorial_note', true);
            if (!is_array($notes)) {
                $notes = [];
            }

            $current_user = wp_get_current_user();
            $notes[] = [
                'text' => sanitize_textarea_field($note_text),
                'author' => $current_user->display_name ?: 'System',
                'timestamp' => current_time('c'),
            ];

            update_post_meta($post_id, '_nibwp_editorial_note', $notes);

            return [
                'drafts' => [],
                'post' => ['id' => $post_id, 'title' => get_the_title($post), 'notes' => $notes],
                'message' => 'Note added.',
            ];

        case 'set_priority':
            $priority = $input['priority'] ?? '';
            $valid = ['low', 'medium', 'high', 'urgent'];
            if (!in_array($priority, $valid, true)) {
                return new WP_Error('invalid_priority', sprintf('priority must be one of: %s.', implode(', ', $valid)));
            }

            update_post_meta($post_id, '_nibwp_priority', $priority);

            return [
                'drafts' => [],
                'post' => ['id' => $post_id, 'title' => get_the_title($post), 'priority' => $priority],
                'message' => sprintf('Priority set to "%s".', $priority),
            ];

        case 'convert_to_scheduled':
            if ($post->post_status !== 'draft') {
                return new WP_Error('not_draft', sprintf('Post %d has status "%s", not "draft".', $post_id, $post->post_status));
            }

            $publish_date = $input['publish_date'] ?? '';
            $timestamp = strtotime($publish_date);
            if ($timestamp === false) {
                return new WP_Error('invalid_date', 'publish_date must be a valid ISO 8601 date.');
            }

            $gmt_timestamp = get_gmt_from_date(date('Y-m-d H:i:s', $timestamp), 'U');
            if ((int) $gmt_timestamp <= time()) {
                return new WP_Error('date_in_past', 'publish_date must be in the future.');
            }

            $post_date = date('Y-m-d H:i:s', $timestamp);
            $post_date_gmt = get_gmt_from_date($post_date);

            $result = wp_update_post([
                'ID' => $post_id,
                'post_status' => 'future',
                'post_date' => $post_date,
                'post_date_gmt' => $post_date_gmt,
            ], true);

            if (is_wp_error($result)) {
                return $result;
            }

            return [
                'drafts' => [],
                'post' => ['id' => $post_id, 'title' => get_the_title($post), 'scheduled_date' => $post_date],
                'message' => sprintf('Draft converted to scheduled post for %s.', $post_date),
            ];

        default:
            return new WP_Error('invalid_action', 'action must be one of: list, add_note, set_priority, convert_to_scheduled.');
    }
}
