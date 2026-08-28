<?php
declare(strict_types=1);

/**
 * Abilities: Admin and Site Enhancements (ASE) custom fields integration.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nibwp/ase-manage-fields', [
    'label' => __('ASE Manage Fields', domain: 'nibwp'),
    'description' => __(
        'Manage Admin and Site Enhancements (ASE) custom field groups, post types, taxonomies, and field values. Supports ASE Pro custom fields module.',
        domain: 'nibwp',
    ),
    'category' => 'custom-fields',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list_field_groups', 'create_field_group', 'list_post_types', 'create_post_type', 'list_taxonomies', 'create_taxonomy', 'get_values', 'update_values'],
                'description' => 'The operation to perform.',
            ],
            'field_group_data' => [
                'type' => 'object',
                'description' => 'Data for creating a field group. Required for create_field_group.',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Field group title.'],
                    'post_types' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Post types to attach the field group to.',
                    ],
                    'context' => ['type' => 'string', 'enum' => ['normal', 'side', 'advanced']],
                    'priority' => ['type' => 'string', 'enum' => ['high', 'default', 'low']],
                    'fields' => [
                        'type' => 'array',
                        'description' => 'Array of field definitions.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'string', 'description' => 'Unique field identifier.'],
                                'label' => ['type' => 'string', 'description' => 'Field display label.'],
                                'type' => ['type' => 'string', 'description' => 'Field type: text, textarea, wysiwyg, number, email, url, date, select, checkbox, radio, image, file, color, etc.'],
                                'description' => ['type' => 'string', 'description' => 'Help text shown below the field.'],
                                'required' => ['type' => 'boolean'],
                                'default' => ['description' => 'Default value.'],
                                'choices' => [
                                    'type' => 'object',
                                    'description' => 'Choices for select/radio/checkbox fields. Keys are values, values are labels.',
                                    'additionalProperties' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'post_type_data' => [
                'type' => 'object',
                'description' => 'Data for creating a post type. Required for create_post_type.',
                'properties' => [
                    'slug' => ['type' => 'string', 'description' => 'Post type slug (max 20 chars).'],
                    'singular_name' => ['type' => 'string'],
                    'plural_name' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'public' => ['type' => 'boolean'],
                    'has_archive' => ['type' => 'boolean'],
                    'show_in_rest' => ['type' => 'boolean'],
                    'menu_icon' => ['type' => 'string'],
                    'supports' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
            ],
            'taxonomy_data' => [
                'type' => 'object',
                'description' => 'Data for creating a taxonomy. Required for create_taxonomy.',
                'properties' => [
                    'slug' => ['type' => 'string'],
                    'singular_name' => ['type' => 'string'],
                    'plural_name' => ['type' => 'string'],
                    'post_types' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'hierarchical' => ['type' => 'boolean'],
                    'public' => ['type' => 'boolean'],
                    'show_in_rest' => ['type' => 'boolean'],
                ],
            ],
            'post_id' => [
                'type' => 'integer',
                'description' => 'Post ID for get_values and update_values.',
            ],
            'field_ids' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Specific field IDs to get for get_values.',
            ],
            'values' => [
                'type' => 'object',
                'description' => 'Key-value pairs to update for update_values. Keys are field IDs.',
                'additionalProperties' => true,
            ],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'data' => ['description' => 'Field groups, post types, taxonomies, field values, or operation result.'],
            'message' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => 'nibwp_ase_manage_fields',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage Admin and Site Enhancements (ASE) custom field groups, post types, taxonomies, and values.',
                '',
                'ACTIONS:',
                '- list_field_groups: Lists all ASE custom field groups.',
                '- create_field_group: Creates a new field group. Requires field_group_data with title and fields.',
                '- list_post_types: Lists all ASE-registered custom post types.',
                '- create_post_type: Creates a custom post type. Requires post_type_data with slug and names.',
                '- list_taxonomies: Lists all ASE-registered custom taxonomies.',
                '- create_taxonomy: Creates a taxonomy. Requires taxonomy_data.',
                '- get_values: Gets field values for a post. Requires post_id, optionally field_ids.',
                '- update_values: Updates field values. Requires post_id and values.',
                '',
                'PREREQUISITES:',
                '- ASE Pro or ASE with Custom Fields module must be active.',
                '- ASE stores its configurations as WordPress options and custom post types.',
                '- Field groups are stored as posts of type "asenha_field_group" (or similar).',
                '- The exact API depends on ASE version; operations use try/catch with fallbacks.',
                '',
                'FIELD TYPES:',
                'text, textarea, wysiwyg, number, email, url, date, datetime, time,',
                'select, checkbox, radio, image, file, gallery, color, range, true_false.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Check if ASE custom fields module is available.
 *
 * @return bool
 */
function nibwp_ase_is_available(): bool
{
    // Check for ASE Developer flavor
    if (defined('FLAVOR') && FLAVOR === 'developer') {
        return true;
    }
    // Check for ASE custom fields class
    if (class_exists('JEsuspended\ASE\Custom_Fields') || class_exists('ASE\Custom_Fields') || class_exists('Jesuspended\Custom_Fields')) {
        return true;
    }
    // Check for the ASE Pro plugin with custom fields
    if (defined('JEAPSED_VERSION') || defined('JEASSED_VERSION') || defined('ASE_VERSION')) {
        return true;
    }
    // Check if the asenha option exists (ASE stores settings in an option)
    $options = get_option('admin_site_enhancements', []);
    if (!empty($options) && is_array($options)) {
        return true;
    }
    return false;
}

/**
 * Execute ASE field management operations.
 *
 * @param array $input Input parameters.
 * @return array|WP_Error
 */
function nibwp_ase_manage_fields(array $input): array|WP_Error
{
    if (!nibwp_ase_is_available()) {
        return new WP_Error(
            'ase_not_active',
            'Admin and Site Enhancements (ASE) plugin with custom fields support is not active. Please install and activate ASE Pro or enable the custom fields module.',
        );
    }

    $action = (string) $input['action'];

    switch ($action) {
        case 'list_field_groups':
            try {
                // ASE stores field groups as a custom post type or in options
                // Try the custom post type approach first
                $field_group_post_type = null;
                foreach (['asenha_field_group', 'ase_field_group', 'asenha_cf_group'] as $candidate) {
                    if (post_type_exists($candidate)) {
                        $field_group_post_type = $candidate;
                        break;
                    }
                }

                if ($field_group_post_type !== null) {
                    $posts = get_posts([
                        'post_type' => $field_group_post_type,
                        'post_status' => 'publish',
                        'posts_per_page' => -1,
                        'orderby' => 'title',
                        'order' => 'ASC',
                    ]);
                    $result = [];
                    foreach ($posts as $post) {
                        $meta = get_post_meta($post->ID);
                        $result[] = [
                            'id' => $post->ID,
                            'title' => $post->post_title,
                            'slug' => $post->post_name,
                            'status' => $post->post_status,
                            'meta' => array_map(static function ($v) {
                                return maybe_unserialize($v[0] ?? '');
                            }, $meta),
                        ];
                    }
                    return ['success' => true, 'data' => $result, 'message' => sprintf('Found %d field groups.', count($result))];
                }

                // Fallback: check options storage
                $options = get_option('admin_site_enhancements', []);
                $field_groups = $options['custom_field_groups'] ?? [];
                if (!empty($field_groups) && is_array($field_groups)) {
                    return ['success' => true, 'data' => $field_groups, 'message' => sprintf('Found %d field groups from options.', count($field_groups))];
                }

                return ['success' => true, 'data' => [], 'message' => 'No field groups found. ASE may store them differently in this version.'];
            } catch (\Throwable $e) {
                return new WP_Error('list_field_groups_failed', sprintf('Failed to list field groups: %s', $e->getMessage()));
            }

        case 'create_field_group':
            $fg_data = isset($input['field_group_data']) ? (array) $input['field_group_data'] : [];
            if (empty($fg_data['title'])) {
                return new WP_Error('missing_title', 'field_group_data.title is required for create_field_group action.');
            }
            try {
                // Try creating as a custom post type entry
                $field_group_post_type = null;
                foreach (['asenha_field_group', 'ase_field_group', 'asenha_cf_group'] as $candidate) {
                    if (post_type_exists($candidate)) {
                        $field_group_post_type = $candidate;
                        break;
                    }
                }

                if ($field_group_post_type !== null) {
                    $post_id = wp_insert_post([
                        'post_type' => $field_group_post_type,
                        'post_title' => (string) $fg_data['title'],
                        'post_status' => 'publish',
                    ]);
                    if (is_wp_error($post_id)) {
                        return $post_id;
                    }
                    if (!empty($fg_data['fields'])) {
                        update_post_meta($post_id, '_ase_fields', $fg_data['fields']);
                    }
                    if (!empty($fg_data['post_types'])) {
                        update_post_meta($post_id, '_ase_post_types', $fg_data['post_types']);
                    }
                    if (!empty($fg_data['context'])) {
                        update_post_meta($post_id, '_ase_context', $fg_data['context']);
                    }
                    if (!empty($fg_data['priority'])) {
                        update_post_meta($post_id, '_ase_priority', $fg_data['priority']);
                    }
                    return ['success' => true, 'data' => ['id' => $post_id, 'title' => (string) $fg_data['title']], 'message' => 'Field group created.'];
                }

                // Fallback: store in options
                $options = get_option('admin_site_enhancements', []);
                if (!is_array($options)) {
                    $options = [];
                }
                $field_groups = $options['custom_field_groups'] ?? [];
                if (!is_array($field_groups)) {
                    $field_groups = [];
                }
                $new_group = [
                    'id' => wp_generate_uuid4(),
                    'title' => (string) $fg_data['title'],
                    'post_types' => $fg_data['post_types'] ?? ['post'],
                    'context' => $fg_data['context'] ?? 'normal',
                    'priority' => $fg_data['priority'] ?? 'default',
                    'fields' => $fg_data['fields'] ?? [],
                ];
                $field_groups[] = $new_group;
                $options['custom_field_groups'] = $field_groups;
                update_option('admin_site_enhancements', $options);
                return ['success' => true, 'data' => $new_group, 'message' => 'Field group created in ASE options. It may require ASE to recognize the structure.'];
            } catch (\Throwable $e) {
                return new WP_Error('create_field_group_failed', sprintf('Failed to create field group: %s', $e->getMessage()));
            }

        case 'list_post_types':
            try {
                // ASE custom post types are typically stored in options
                $options = get_option('admin_site_enhancements', []);
                $custom_post_types = $options['custom_content_type'] ?? $options['custom_post_types'] ?? [];

                if (!empty($custom_post_types) && is_array($custom_post_types)) {
                    return ['success' => true, 'data' => $custom_post_types, 'message' => sprintf('Found %d ASE post types.', count($custom_post_types))];
                }

                // Check for ASE CPT post type storage
                foreach (['asenha_cpt', 'ase_cpt', 'asenha_content_type'] as $candidate) {
                    if (post_type_exists($candidate)) {
                        $posts = get_posts([
                            'post_type' => $candidate,
                            'post_status' => 'publish',
                            'posts_per_page' => -1,
                        ]);
                        $result = [];
                        foreach ($posts as $post) {
                            $result[] = [
                                'id' => $post->ID,
                                'title' => $post->post_title,
                                'slug' => $post->post_name,
                            ];
                        }
                        return ['success' => true, 'data' => $result, 'message' => sprintf('Found %d ASE post types.', count($result))];
                    }
                }

                return ['success' => true, 'data' => [], 'message' => 'No ASE post types found.'];
            } catch (\Throwable $e) {
                return new WP_Error('list_post_types_failed', sprintf('Failed to list post types: %s', $e->getMessage()));
            }

        case 'create_post_type':
            $pt_data = isset($input['post_type_data']) ? (array) $input['post_type_data'] : [];
            if (empty($pt_data['slug'])) {
                return new WP_Error('missing_slug', 'post_type_data.slug is required.');
            }
            $slug = sanitize_key((string) $pt_data['slug']);
            if (strlen($slug) > 20) {
                return new WP_Error('slug_too_long', 'Post type slug must be 20 characters or fewer.');
            }
            if (post_type_exists($slug)) {
                return new WP_Error('post_type_exists', sprintf('Post type already exists: %s', $slug));
            }
            try {
                $singular = $pt_data['singular_name'] ?? ucfirst($slug);
                $plural = $pt_data['plural_name'] ?? $singular . 's';

                // Register via WordPress core
                $args = [
                    'labels' => [
                        'name' => $plural,
                        'singular_name' => $singular,
                        'add_new' => sprintf('Add New %s', $singular),
                        'add_new_item' => sprintf('Add New %s', $singular),
                        'edit_item' => sprintf('Edit %s', $singular),
                        'new_item' => sprintf('New %s', $singular),
                        'view_item' => sprintf('View %s', $singular),
                        'search_items' => sprintf('Search %s', $plural),
                        'not_found' => sprintf('No %s found', strtolower($plural)),
                    ],
                    'public' => $pt_data['public'] ?? true,
                    'has_archive' => $pt_data['has_archive'] ?? true,
                    'show_in_rest' => $pt_data['show_in_rest'] ?? true,
                    'supports' => $pt_data['supports'] ?? ['title', 'editor', 'thumbnail'],
                    'menu_icon' => $pt_data['menu_icon'] ?? 'dashicons-admin-post',
                    'description' => $pt_data['description'] ?? '',
                ];

                $result = register_post_type($slug, $args);
                if (is_wp_error($result)) {
                    return $result;
                }

                // Try to persist in ASE options
                $options = get_option('admin_site_enhancements', []);
                if (is_array($options)) {
                    $cpts = $options['custom_content_type'] ?? $options['custom_post_types'] ?? [];
                    if (!is_array($cpts)) {
                        $cpts = [];
                    }
                    $cpts[] = [
                        'slug' => $slug,
                        'singular_name' => $singular,
                        'plural_name' => $plural,
                        'public' => $pt_data['public'] ?? true,
                        'has_archive' => $pt_data['has_archive'] ?? true,
                        'show_in_rest' => $pt_data['show_in_rest'] ?? true,
                    ];
                    $options['custom_content_type'] = $cpts;
                    update_option('admin_site_enhancements', $options);
                }

                return ['success' => true, 'data' => ['slug' => $slug, 'singular' => $singular, 'plural' => $plural], 'message' => 'Post type registered. Saved to ASE options for persistence.'];
            } catch (\Throwable $e) {
                return new WP_Error('create_post_type_failed', sprintf('Failed to create post type: %s', $e->getMessage()));
            }

        case 'list_taxonomies':
            try {
                $options = get_option('admin_site_enhancements', []);
                $taxonomies = $options['custom_taxonomies'] ?? [];

                if (!empty($taxonomies) && is_array($taxonomies)) {
                    return ['success' => true, 'data' => $taxonomies, 'message' => sprintf('Found %d ASE taxonomies.', count($taxonomies))];
                }

                foreach (['asenha_taxonomy', 'ase_taxonomy'] as $candidate) {
                    if (post_type_exists($candidate)) {
                        $posts = get_posts([
                            'post_type' => $candidate,
                            'post_status' => 'publish',
                            'posts_per_page' => -1,
                        ]);
                        $result = [];
                        foreach ($posts as $post) {
                            $result[] = [
                                'id' => $post->ID,
                                'title' => $post->post_title,
                                'slug' => $post->post_name,
                            ];
                        }
                        return ['success' => true, 'data' => $result, 'message' => sprintf('Found %d ASE taxonomies.', count($result))];
                    }
                }

                return ['success' => true, 'data' => [], 'message' => 'No ASE taxonomies found.'];
            } catch (\Throwable $e) {
                return new WP_Error('list_taxonomies_failed', sprintf('Failed to list taxonomies: %s', $e->getMessage()));
            }

        case 'create_taxonomy':
            $tax_data = isset($input['taxonomy_data']) ? (array) $input['taxonomy_data'] : [];
            if (empty($tax_data['slug'])) {
                return new WP_Error('missing_slug', 'taxonomy_data.slug is required.');
            }
            $slug = sanitize_key((string) $tax_data['slug']);
            if (taxonomy_exists($slug)) {
                return new WP_Error('taxonomy_exists', sprintf('Taxonomy already exists: %s', $slug));
            }
            try {
                $singular = $tax_data['singular_name'] ?? ucfirst($slug);
                $plural = $tax_data['plural_name'] ?? $singular . 's';
                $post_types = $tax_data['post_types'] ?? ['post'];

                $args = [
                    'labels' => [
                        'name' => $plural,
                        'singular_name' => $singular,
                        'search_items' => sprintf('Search %s', $plural),
                        'all_items' => sprintf('All %s', $plural),
                        'edit_item' => sprintf('Edit %s', $singular),
                        'add_new_item' => sprintf('Add New %s', $singular),
                    ],
                    'public' => $tax_data['public'] ?? true,
                    'hierarchical' => $tax_data['hierarchical'] ?? true,
                    'show_in_rest' => $tax_data['show_in_rest'] ?? true,
                ];

                $result = register_taxonomy($slug, $post_types, $args);
                if (is_wp_error($result)) {
                    return $result;
                }

                // Try to persist in ASE options
                $options = get_option('admin_site_enhancements', []);
                if (is_array($options)) {
                    $taxonomies = $options['custom_taxonomies'] ?? [];
                    if (!is_array($taxonomies)) {
                        $taxonomies = [];
                    }
                    $taxonomies[] = [
                        'slug' => $slug,
                        'singular_name' => $singular,
                        'plural_name' => $plural,
                        'post_types' => $post_types,
                        'hierarchical' => $tax_data['hierarchical'] ?? true,
                    ];
                    $options['custom_taxonomies'] = $taxonomies;
                    update_option('admin_site_enhancements', $options);
                }

                return ['success' => true, 'data' => ['slug' => $slug, 'post_types' => $post_types], 'message' => 'Taxonomy registered. Saved to ASE options for persistence.'];
            } catch (\Throwable $e) {
                return new WP_Error('create_taxonomy_failed', sprintf('Failed to create taxonomy: %s', $e->getMessage()));
            }

        case 'get_values':
            $post_id = isset($input['post_id']) ? (int) $input['post_id'] : 0;
            if ($post_id <= 0) {
                return new WP_Error('missing_post_id', 'post_id is required for get_values action.');
            }
            $field_ids = isset($input['field_ids']) ? (array) $input['field_ids'] : [];
            try {
                $values = [];
                if (!empty($field_ids)) {
                    foreach ($field_ids as $field_id) {
                        $values[(string) $field_id] = get_post_meta($post_id, (string) $field_id, true);
                    }
                } else {
                    // Get all non-internal meta
                    $all_meta = get_post_meta($post_id);
                    foreach ($all_meta as $key => $meta_values) {
                        if (str_starts_with($key, '_')) {
                            continue;
                        }
                        $values[$key] = maybe_unserialize($meta_values[0] ?? '');
                    }
                }
                return ['success' => true, 'data' => $values, 'message' => sprintf('Retrieved %d field values.', count($values))];
            } catch (\Throwable $e) {
                return new WP_Error('get_values_failed', sprintf('Failed to get values: %s', $e->getMessage()));
            }

        case 'update_values':
            $post_id = isset($input['post_id']) ? (int) $input['post_id'] : 0;
            $values = isset($input['values']) ? (array) $input['values'] : [];
            if ($post_id <= 0) {
                return new WP_Error('missing_post_id', 'post_id is required for update_values action.');
            }
            if (empty($values)) {
                return new WP_Error('missing_values', 'values object is required for update_values action.');
            }
            try {
                $updated = [];
                $errors = [];
                foreach ($values as $field_id => $value) {
                    $result = update_post_meta($post_id, (string) $field_id, $value);
                    if ($result === false) {
                        // update_post_meta returns false if value is unchanged or on failure
                        // Check if the value already matches
                        $current = get_post_meta($post_id, (string) $field_id, true);
                        if ($current === $value) {
                            $updated[] = (string) $field_id; // Already correct
                        } else {
                            $errors[] = (string) $field_id;
                        }
                    } else {
                        $updated[] = (string) $field_id;
                    }
                }
                $data = ['updated' => $updated];
                if (!empty($errors)) {
                    $data['failed'] = $errors;
                }
                return ['success' => true, 'data' => $data, 'message' => sprintf('Updated %d fields, %d failed.', count($updated), count($errors))];
            } catch (\Throwable $e) {
                return new WP_Error('update_values_failed', sprintf('Failed to update values: %s', $e->getMessage()));
            }

        default:
            return new WP_Error('invalid_action', sprintf('Unknown action: %s', $action));
    }
}
