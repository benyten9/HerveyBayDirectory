<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

// --- Ability: nibwp/wpjm-list-jobs ---

wp_register_ability('nibwp/wpjm-list-jobs', [
    'label' => __('WP Job Manager – List Jobs', domain: 'nibwp'),
    'description' => __(
        'List WP Job Manager job listings with filters: status, job type, category, location, featured/filled, search, and pagination.',
        domain: 'nibwp',
    ),
    'category' => 'wp-job-manager',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'status' => [
                'type' => 'string',
                'enum' => ['publish', 'pending', 'expired', 'preview', 'draft', 'any'],
                'default' => 'publish',
                'description' => 'Job listing status.',
            ],
            'job_type' => [
                'type' => 'string',
                'description' => 'Job type slug (e.g. "full-time", "part-time").',
            ],
            'category' => [
                'type' => 'string',
                'description' => 'Job category slug (requires Job Categories enabled).',
            ],
            'location' => [
                'type' => 'string',
                'description' => 'Location keyword (matches _job_location meta).',
            ],
            'search' => [
                'type' => 'string',
                'description' => 'Search term across title and content.',
            ],
            'featured' => ['type' => 'boolean'],
            'filled' => ['type' => 'boolean'],
            'remote' => ['type' => 'boolean'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page' => ['type' => 'integer', 'default' => 1],
            'orderby' => [
                'type' => 'string',
                'enum' => ['date', 'title', 'menu_order'],
                'default' => 'date',
            ],
            'order' => [
                'type' => 'string',
                'enum' => ['ASC', 'DESC'],
                'default' => 'DESC',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'jobs' => ['type' => 'array'],
            'total' => ['type' => 'integer'],
            'pages' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => 'nibwp_wpjm_list_jobs',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'List WP Job Manager listings (post type: job_listing).',
                '',
                'FILTERS:',
                '- status: publish (default), pending, expired, preview, draft, any.',
                '- job_type / category: taxonomy slugs.',
                '- location: substring match against _job_location meta.',
                '- featured / filled / remote: boolean meta filters.',
                '- search: keyword across title and content.',
                '',
                'PAGINATION: per_page (default 20, max 100), page (default 1).',
                'SORTING: orderby (date/title/menu_order) + order (ASC/DESC).',
                '',
                'REQUIRES: WP Job Manager plugin must be active.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wpjm_list_jobs(array $input): array|WP_Error
{
    if (!defined('JOB_MANAGER_VERSION') && !class_exists('WP_Job_Manager')) {
        return new WP_Error('wpjm_not_active', 'WP Job Manager is not active.');
    }

    $per_page = min(max((int) ($input['per_page'] ?? 20), 1), 100);
    $page = max((int) ($input['page'] ?? 1), 1);

    $args = [
        'post_type' => 'job_listing',
        'post_status' => ($input['status'] ?? 'publish') === 'any'
            ? ['publish', 'pending', 'expired', 'preview', 'draft']
            : (string) ($input['status'] ?? 'publish'),
        'posts_per_page' => $per_page,
        'paged' => $page,
        'orderby' => (string) ($input['orderby'] ?? 'date'),
        'order' => (string) ($input['order'] ?? 'DESC'),
    ];

    if (!empty($input['search'])) {
        $args['s'] = (string) $input['search'];
    }

    $tax_query = [];
    if (!empty($input['job_type'])) {
        $tax_query[] = [
            'taxonomy' => 'job_listing_type',
            'field' => 'slug',
            'terms' => (string) $input['job_type'],
        ];
    }
    if (!empty($input['category'])) {
        $tax_query[] = [
            'taxonomy' => 'job_listing_category',
            'field' => 'slug',
            'terms' => (string) $input['category'],
        ];
    }
    if (count($tax_query) > 1) {
        $tax_query['relation'] = 'AND';
    }
    if ($tax_query) {
        $args['tax_query'] = $tax_query;
    }

    $meta_query = [];
    if (!empty($input['location'])) {
        $meta_query[] = [
            'key' => '_job_location',
            'value' => (string) $input['location'],
            'compare' => 'LIKE',
        ];
    }
    if (isset($input['featured'])) {
        $meta_query[] = [
            'key' => '_featured',
            'value' => $input['featured'] ? '1' : '',
            'compare' => $input['featured'] ? '=' : 'IN',
            'compare_value' => $input['featured'] ? '1' : ['', '0'],
        ];
    }
    if (isset($input['filled'])) {
        $meta_query[] = [
            'key' => '_filled',
            'value' => $input['filled'] ? '1' : '0',
            'compare' => '=',
        ];
    }
    if (isset($input['remote'])) {
        $meta_query[] = [
            'key' => '_remote_position',
            'value' => $input['remote'] ? '1' : '0',
            'compare' => '=',
        ];
    }
    if ($meta_query) {
        if (count($meta_query) > 1) {
            $meta_query['relation'] = 'AND';
        }
        $args['meta_query'] = $meta_query;
    }

    $query = new WP_Query($args);

    $jobs = [];
    foreach ($query->posts as $post) {
        $jobs[] = nibwp_wpjm_format_job($post);
    }

    return [
        'jobs' => $jobs,
        'total' => (int) $query->found_posts,
        'pages' => (int) $query->max_num_pages,
    ];
}

// --- Ability: nibwp/wpjm-get-job ---

wp_register_ability('nibwp/wpjm-get-job', [
    'label' => __('WP Job Manager – Get Job', domain: 'nibwp'),
    'description' => __('Get full details of a single job listing including company info, location, application data, and all meta.', domain: 'nibwp'),
    'category' => 'wp-job-manager',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'job_id' => ['type' => 'integer'],
        ],
        'required' => ['job_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'job' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => 'nibwp_wpjm_get_job',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Retrieve full data for a single WP Job Manager listing by ID. Includes content, company info, location, application contact, taxonomies, and all meta. REQUIRES: WP Job Manager plugin active.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wpjm_get_job(array $input): array|WP_Error
{
    if (!defined('JOB_MANAGER_VERSION') && !class_exists('WP_Job_Manager')) {
        return new WP_Error('wpjm_not_active', 'WP Job Manager is not active.');
    }

    $post = get_post((int) ($input['job_id'] ?? 0));
    if (!$post || $post->post_type !== 'job_listing') {
        return new WP_Error('wpjm_not_found', 'Job listing not found.');
    }

    return ['job' => nibwp_wpjm_format_job($post, full: true)];
}

// --- Ability: nibwp/wpjm-create-job ---

wp_register_ability('nibwp/wpjm-create-job', [
    'label' => __('WP Job Manager – Create Job', domain: 'nibwp'),
    'description' => __('Create a new job listing with title, description, location, company details, application contact, and taxonomies.', domain: 'nibwp'),
    'category' => 'wp-job-manager',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'title' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'status' => [
                'type' => 'string',
                'enum' => ['publish', 'pending', 'draft', 'preview'],
                'default' => 'publish',
            ],
            'location' => ['type' => 'string', 'description' => 'Stored in _job_location meta.'],
            'remote' => ['type' => 'boolean'],
            'job_types' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Slugs of job_listing_type terms.',
            ],
            'categories' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Slugs of job_listing_category terms.',
            ],
            'company_name' => ['type' => 'string'],
            'company_website' => ['type' => 'string'],
            'company_tagline' => ['type' => 'string'],
            'company_twitter' => ['type' => 'string'],
            'company_video' => ['type' => 'string'],
            'company_logo_id' => ['type' => 'integer', 'description' => 'Attachment ID for company logo.'],
            'application' => ['type' => 'string', 'description' => 'Application email or URL.'],
            'featured' => ['type' => 'boolean'],
            'expires' => ['type' => 'string', 'description' => 'Expiry date YYYY-MM-DD.'],
        ],
        'required' => ['title'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'job' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => 'nibwp_wpjm_create_job',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Create a new job_listing post in WP Job Manager.',
                '',
                'REQUIRED: title.',
                'STATUS defaults to publish; use pending for moderation queue.',
                '',
                'TAXONOMIES: job_types (job_listing_type slugs) and categories (job_listing_category slugs).',
                'COMPANY: name/website/tagline/twitter/video/logo_id all stored as _company_* meta.',
                'APPLICATION: email or URL stored as _application meta.',
                'EXPIRY: YYYY-MM-DD string stored as _job_expires.',
                '',
                'REQUIRES: WP Job Manager plugin active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_wpjm_create_job(array $input): array|WP_Error
{
    if (!defined('JOB_MANAGER_VERSION') && !class_exists('WP_Job_Manager')) {
        return new WP_Error('wpjm_not_active', 'WP Job Manager is not active.');
    }

    $post_id = wp_insert_post([
        'post_type' => 'job_listing',
        'post_title' => sanitize_text_field((string) $input['title']),
        'post_content' => (string) ($input['description'] ?? ''),
        'post_status' => (string) ($input['status'] ?? 'publish'),
    ], wp_error: true);

    if (is_wp_error($post_id)) {
        return $post_id;
    }

    nibwp_wpjm_apply_meta_and_terms((int) $post_id, $input);

    return ['job' => nibwp_wpjm_format_job(get_post((int) $post_id), full: true)];
}

// --- Ability: nibwp/wpjm-update-job ---

wp_register_ability('nibwp/wpjm-update-job', [
    'label' => __('WP Job Manager – Update Job', domain: 'nibwp'),
    'description' => __('Update an existing job listing. Only provided fields are changed.', domain: 'nibwp'),
    'category' => 'wp-job-manager',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'job_id' => ['type' => 'integer'],
            'title' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'status' => [
                'type' => 'string',
                'enum' => ['publish', 'pending', 'draft', 'preview', 'expired'],
            ],
            'location' => ['type' => 'string'],
            'remote' => ['type' => 'boolean'],
            'job_types' => ['type' => 'array', 'items' => ['type' => 'string']],
            'categories' => ['type' => 'array', 'items' => ['type' => 'string']],
            'company_name' => ['type' => 'string'],
            'company_website' => ['type' => 'string'],
            'company_tagline' => ['type' => 'string'],
            'company_twitter' => ['type' => 'string'],
            'company_video' => ['type' => 'string'],
            'company_logo_id' => ['type' => 'integer'],
            'application' => ['type' => 'string'],
            'featured' => ['type' => 'boolean'],
            'filled' => ['type' => 'boolean'],
            'expires' => ['type' => 'string'],
        ],
        'required' => ['job_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'job' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => 'nibwp_wpjm_update_job',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Update a job_listing. Only fields provided in input are modified; others stay unchanged. REQUIRES: WP Job Manager plugin active.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wpjm_update_job(array $input): array|WP_Error
{
    if (!defined('JOB_MANAGER_VERSION') && !class_exists('WP_Job_Manager')) {
        return new WP_Error('wpjm_not_active', 'WP Job Manager is not active.');
    }

    $post_id = (int) ($input['job_id'] ?? 0);
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'job_listing') {
        return new WP_Error('wpjm_not_found', 'Job listing not found.');
    }

    $update = ['ID' => $post_id];
    if (array_key_exists('title', $input)) {
        $update['post_title'] = sanitize_text_field((string) $input['title']);
    }
    if (array_key_exists('description', $input)) {
        $update['post_content'] = (string) $input['description'];
    }
    if (array_key_exists('status', $input)) {
        $update['post_status'] = (string) $input['status'];
    }
    if (count($update) > 1) {
        $result = wp_update_post($update, wp_error: true);
        if (is_wp_error($result)) {
            return $result;
        }
    }

    nibwp_wpjm_apply_meta_and_terms($post_id, $input);

    return ['job' => nibwp_wpjm_format_job(get_post($post_id), full: true)];
}

// --- Ability: nibwp/wpjm-delete-job ---

wp_register_ability('nibwp/wpjm-delete-job', [
    'label' => __('WP Job Manager – Delete Job', domain: 'nibwp'),
    'description' => __('Delete a job listing. Default trashes; pass force=true to permanently delete.', domain: 'nibwp'),
    'category' => 'wp-job-manager',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'job_id' => ['type' => 'integer'],
            'force' => ['type' => 'boolean', 'default' => false],
        ],
        'required' => ['job_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'deleted' => ['type' => 'boolean'],
            'forced' => ['type' => 'boolean'],
        ],
    ],
    'execute_callback' => 'nibwp_wpjm_delete_job',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Delete a job_listing. force=false trashes (recoverable); force=true permanently deletes. REQUIRES: WP Job Manager active.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wpjm_delete_job(array $input): array|WP_Error
{
    if (!defined('JOB_MANAGER_VERSION') && !class_exists('WP_Job_Manager')) {
        return new WP_Error('wpjm_not_active', 'WP Job Manager is not active.');
    }

    $post_id = (int) ($input['job_id'] ?? 0);
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'job_listing') {
        return new WP_Error('wpjm_not_found', 'Job listing not found.');
    }

    $force = (bool) ($input['force'] ?? false);
    $result = $force ? wp_delete_post($post_id, force_delete: true) : wp_trash_post($post_id);

    return [
        'deleted' => (bool) $result,
        'forced' => $force,
    ];
}

// --- Ability: nibwp/wpjm-list-taxonomies ---

wp_register_ability('nibwp/wpjm-list-taxonomies', [
    'label' => __('WP Job Manager – List Job Types & Categories', domain: 'nibwp'),
    'description' => __('List all terms for job_listing_type and job_listing_category taxonomies.', domain: 'nibwp'),
    'category' => 'wp-job-manager',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'taxonomy' => [
                'type' => 'string',
                'enum' => ['job_listing_type', 'job_listing_category', 'all'],
                'default' => 'all',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'job_types' => ['type' => 'array'],
            'categories' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => 'nibwp_wpjm_list_taxonomies',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'List terms for WP Job Manager taxonomies. Use the slugs returned when creating or filtering jobs. Categories taxonomy may be disabled in WPJM settings — empty array means disabled. REQUIRES: WP Job Manager active.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wpjm_list_taxonomies(array $input): array|WP_Error
{
    if (!defined('JOB_MANAGER_VERSION') && !class_exists('WP_Job_Manager')) {
        return new WP_Error('wpjm_not_active', 'WP Job Manager is not active.');
    }

    $which = (string) ($input['taxonomy'] ?? 'all');
    $result = ['job_types' => [], 'categories' => []];

    if ($which === 'all' || $which === 'job_listing_type') {
        $result['job_types'] = nibwp_wpjm_format_terms('job_listing_type');
    }
    if ($which === 'all' || $which === 'job_listing_category') {
        $result['categories'] = nibwp_wpjm_format_terms('job_listing_category');
    }

    return $result;
}

// --- Helpers (internal) ---

/**
 * Format a job_listing post into an array.
 */
function nibwp_wpjm_format_job(WP_Post $post, bool $full = false): array
{
    $base = [
        'id' => $post->ID,
        'title' => $post->post_title,
        'slug' => $post->post_name,
        'status' => $post->post_status,
        'date' => $post->post_date_gmt,
        'modified' => $post->post_modified_gmt,
        'url' => get_permalink($post),
        'location' => (string) get_post_meta($post->ID, '_job_location', true),
        'remote' => (bool) get_post_meta($post->ID, '_remote_position', true),
        'featured' => (bool) get_post_meta($post->ID, '_featured', true),
        'filled' => (bool) get_post_meta($post->ID, '_filled', true),
        'expires' => (string) get_post_meta($post->ID, '_job_expires', true),
        'company' => [
            'name' => (string) get_post_meta($post->ID, '_company_name', true),
            'website' => (string) get_post_meta($post->ID, '_company_website', true),
            'tagline' => (string) get_post_meta($post->ID, '_company_tagline', true),
            'twitter' => (string) get_post_meta($post->ID, '_company_twitter', true),
            'video' => (string) get_post_meta($post->ID, '_company_video', true),
            'logo_id' => (int) get_post_meta($post->ID, '_company_logo', true),
        ],
        'application' => (string) get_post_meta($post->ID, '_application', true),
        'job_types' => nibwp_wpjm_post_terms($post->ID, 'job_listing_type'),
        'categories' => nibwp_wpjm_post_terms($post->ID, 'job_listing_category'),
    ];

    if ($full) {
        $base['content'] = $post->post_content;
        $base['author'] = (int) $post->post_author;
        $base['company']['logo_url'] = $base['company']['logo_id']
            ? (string) wp_get_attachment_url($base['company']['logo_id'])
            : '';
    }

    return $base;
}

function nibwp_wpjm_post_terms(int $post_id, string $taxonomy): array
{
    if (!taxonomy_exists($taxonomy)) {
        return [];
    }
    $terms = wp_get_post_terms($post_id, $taxonomy);
    if (is_wp_error($terms)) {
        return [];
    }
    $out = [];
    foreach ($terms as $term) {
        $out[] = ['id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug];
    }
    return $out;
}

function nibwp_wpjm_format_terms(string $taxonomy): array
{
    if (!taxonomy_exists($taxonomy)) {
        return [];
    }
    $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
    if (is_wp_error($terms)) {
        return [];
    }
    $out = [];
    foreach ($terms as $term) {
        $out[] = [
            'id' => $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'count' => (int) $term->count,
        ];
    }
    return $out;
}

/**
 * Apply taxonomy + meta updates from input array to a job_listing post.
 */
function nibwp_wpjm_apply_meta_and_terms(int $post_id, array $input): void
{
    $meta_map = [
        'location' => '_job_location',
        'company_name' => '_company_name',
        'company_website' => '_company_website',
        'company_tagline' => '_company_tagline',
        'company_twitter' => '_company_twitter',
        'company_video' => '_company_video',
        'company_logo_id' => '_company_logo',
        'application' => '_application',
        'expires' => '_job_expires',
    ];
    foreach ($meta_map as $in_key => $meta_key) {
        if (array_key_exists($in_key, $input)) {
            update_post_meta($post_id, $meta_key, $input[$in_key]);
        }
    }

    $bool_map = [
        'featured' => '_featured',
        'filled' => '_filled',
        'remote' => '_remote_position',
    ];
    foreach ($bool_map as $in_key => $meta_key) {
        if (array_key_exists($in_key, $input)) {
            update_post_meta($post_id, $meta_key, $input[$in_key] ? '1' : '0');
        }
    }

    if (array_key_exists('job_types', $input) && taxonomy_exists('job_listing_type')) {
        wp_set_post_terms($post_id, (array) $input['job_types'], 'job_listing_type');
    }
    if (array_key_exists('categories', $input) && taxonomy_exists('job_listing_category')) {
        wp_set_post_terms($post_id, (array) $input['categories'], 'job_listing_category');
    }
}
