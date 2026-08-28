<?php
declare(strict_types=1);

/**
 * Abilities: Meta Box integration.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nibwp/metabox-manage-fields', [
    'label' => __('Meta Box Manage Fields', domain: 'nibwp'),
    'description' => __(
        'Manage Meta Box field groups, custom post types, taxonomies, relationships, and field values. Supports the full Meta Box ecosystem including MB Custom Post Types and MB Relationships.',
        domain: 'nibwp',
    ),
    'category' => 'custom-fields',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list_field_groups', 'create_field_group', 'register_post_type', 'register_taxonomy', 'create_relationship', 'get_values', 'update_values'],
                'description' => 'The operation to perform.',
            ],
            'field_group_data' => [
                'type' => 'object',
                'description' => 'Field group configuration for create_field_group.',
                'properties' => [
                    'id' => ['type' => 'string', 'description' => 'Unique meta box ID.'],
                    'title' => ['type' => 'string', 'description' => 'Meta box title.'],
                    'post_types' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Post types to show the meta box on.',
                    ],
                    'context' => ['type' => 'string', 'enum' => ['normal', 'side', 'form_top', 'after_title']],
                    'priority' => ['type' => 'string', 'enum' => ['high', 'low']],
                    'fields' => [
                        'type' => 'array',
                        'description' => 'Array of field definitions.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                                'type' => ['type' => 'string'],
                                'desc' => ['type' => 'string'],
                                'std' => ['description' => 'Default value.'],
                                'options' => ['type' => 'object', 'description' => 'Options for select/radio/checkbox_list.'],
                            ],
                        ],
                    ],
                ],
            ],
            'post_type_data' => [
                'type' => 'object',
                'description' => 'Post type configuration for register_post_type.',
                'properties' => [
                    'slug' => ['type' => 'string', 'description' => 'Post type slug (max 20 chars).'],
                    'singular_name' => ['type' => 'string'],
                    'plural_name' => ['type' => 'string'],
                    'public' => ['type' => 'boolean'],
                    'has_archive' => ['type' => 'boolean'],
                    'show_in_rest' => ['type' => 'boolean'],
                    'supports' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Supported features: title, editor, thumbnail, etc.',
                    ],
                    'menu_icon' => ['type' => 'string'],
                ],
            ],
            'taxonomy_data' => [
                'type' => 'object',
                'description' => 'Taxonomy configuration for register_taxonomy.',
                'properties' => [
                    'slug' => ['type' => 'string'],
                    'singular_name' => ['type' => 'string'],
                    'plural_name' => ['type' => 'string'],
                    'post_types' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'public' => ['type' => 'boolean'],
                    'hierarchical' => ['type' => 'boolean'],
                    'show_in_rest' => ['type' => 'boolean'],
                ],
            ],
            'relationship_data' => [
                'type' => 'object',
                'description' => 'Relationship configuration for create_relationship.',
                'properties' => [
                    'id' => ['type' => 'string', 'description' => 'Unique relationship ID.'],
                    'from' => [
                        'type' => 'object',
                        'properties' => [
                            'object_type' => ['type' => 'string'],
                            'post_type' => ['type' => 'string'],
                            'meta_box' => ['type' => 'object'],
                        ],
                    ],
                    'to' => [
                        'type' => 'object',
                        'properties' => [
                            'object_type' => ['type' => 'string'],
                            'post_type' => ['type' => 'string'],
                            'meta_box' => ['type' => 'object'],
                        ],
                    ],
                    'reciprocal' => ['type' => 'boolean'],
                ],
            ],
            'post_id' => [
                'type' => 'integer',
                'description' => 'Post ID for get_values and update_values.',
            ],
            'field_ids' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Specific field IDs to retrieve for get_values. If empty, attempts to get all.',
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
            'data' => ['description' => 'Field groups, post types, relationships, field values, or operation result.'],
            'message' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => 'nibwp_metabox_manage_fields',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage Meta Box plugin field groups, post types, taxonomies, relationships, and field values.',
                '',
                'ACTIONS:',
                '- list_field_groups: Lists all registered Meta Box field groups (meta boxes).',
                '- create_field_group: Creates a new field group. Requires field_group_data with id, title, and fields.',
                '  The field group is registered via the rwmb_meta_boxes filter for the current request.',
                '  For persistence, write the registration code to a file.',
                '- register_post_type: Registers a custom post type. Requires post_type_data with slug and names.',
                '  Uses MB Custom Post Types if available, otherwise register_post_type().',
                '- register_taxonomy: Registers a taxonomy. Requires taxonomy_data.',
                '- create_relationship: Creates a relationship between post types. Requires relationship_data.',
                '  Requires MB Relationships extension.',
                '- get_values: Gets field values for a post. Requires post_id, optionally field_ids.',
                '- update_values: Updates field values. Requires post_id and values object.',
                '',
                'FIELD TYPES:',
                'Common: text, textarea, wysiwyg, number, email, url, date, time, datetime,',
                'select, radio, checkbox, checkbox_list, image, file, file_advanced,',
                'image_advanced, group, key_value, map, oembed, slider, color, background.',
                '',
                'IMPORTANT: Meta Box plugin must be active. rwmb_meta() function must exist.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Execute Meta Box field management operations.
 *
 * @param array $input Input parameters.
 * @return array|WP_Error
 */
function nibwp_metabox_manage_fields(array $input): array|WP_Error
{
    if (!function_exists('rwmb_meta')) {
        return new WP_Error(
            'metabox_not_active',
            'Meta Box plugin is not active. Please install and activate Meta Box.',
        );
    }

    $action = (string) $input['action'];

    switch ($action) {
        case 'list_field_groups':
            try {
                // Meta Box stores registered meta boxes via the rwmb_meta_boxes filter.
                // We can access them via the registry if available.
                $registry = rwmb_get_registry('meta_box');
                if ($registry && method_exists($registry, 'all')) {
                    $meta_boxes = $registry->all();
                    $result = [];
                    foreach ($meta_boxes as $meta_box) {
                        $meta_box_data = is_object($meta_box) && method_exists($meta_box, 'meta') ? $meta_box->meta : [];
                        $result[] = [
                            'id' => is_object($meta_box) ? ($meta_box->id ?? '') : ($meta_box['id'] ?? ''),
                            'title' => is_object($meta_box) ? ($meta_box->title ?? '') : ($meta_box['title'] ?? ''),
                            'post_types' => is_object($meta_box) ? ($meta_box->post_types ?? []) : ($meta_box['post_types'] ?? []),
                        ];
                    }
                    return ['success' => true, 'data' => $result, 'message' => sprintf('Found %d field groups.', count($result))];
                }

                // Fallback: apply the filter directly
                $meta_boxes = apply_filters('rwmb_meta_boxes', []);
                $result = [];
                foreach ($meta_boxes as $mb) {
                    $result[] = [
                        'id' => $mb['id'] ?? '',
                        'title' => $mb['title'] ?? '',
                        'post_types' => $mb['post_types'] ?? [],
                        'field_count' => count($mb['fields'] ?? []),
                    ];
                }
                return ['success' => true, 'data' => $result, 'message' => sprintf('Found %d field groups.', count($result))];
            } catch (\Throwable $e) {
                return new WP_Error('list_failed', sprintf('Failed to list field groups: %s', $e->getMessage()));
            }

        case 'create_field_group':
            $fg_data = isset($input['field_group_data']) ? (array) $input['field_group_data'] : [];
            if (empty($fg_data['id']) || empty($fg_data['title'])) {
                return new WP_Error('missing_field_group_data', 'field_group_data.id and field_group_data.title are required.');
            }
            try {
                $meta_box = [
                    'id' => sanitize_key((string) $fg_data['id']),
                    'title' => (string) $fg_data['title'],
                    'post_types' => $fg_data['post_types'] ?? ['post'],
                    'context' => $fg_data['context'] ?? 'normal',
                    'priority' => $fg_data['priority'] ?? 'high',
                    'fields' => $fg_data['fields'] ?? [],
                ];

                // Register the meta box for this request via the filter
                add_filter('rwmb_meta_boxes', static function (array $meta_boxes) use ($meta_box): array {
                    $meta_boxes[] = $meta_box;
                    return $meta_boxes;
                });

                return [
                    'success' => true,
                    'data' => $meta_box,
                    'message' => 'Field group registered for this request. To persist, save the registration code to a plugin file.',
                ];
            } catch (\Throwable $e) {
                return new WP_Error('create_failed', sprintf('Failed to create field group: %s', $e->getMessage()));
            }

        case 'register_post_type':
            $pt_data = isset($input['post_type_data']) ? (array) $input['post_type_data'] : [];
            if (empty($pt_data['slug'])) {
                return new WP_Error('missing_slug', 'post_type_data.slug is required.');
            }
            $slug = sanitize_key((string) $pt_data['slug']);
            if (strlen($slug) > 20) {
                return new WP_Error('slug_too_long', 'Post type slug must be 20 characters or fewer.');
            }
            $singular = $pt_data['singular_name'] ?? ucfirst($slug);
            $plural = $pt_data['plural_name'] ?? $singular . 's';
            try {
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
                ];
                $result = register_post_type($slug, $args);
                if (is_wp_error($result)) {
                    return $result;
                }
                return ['success' => true, 'data' => ['slug' => $slug, 'labels' => $args['labels']], 'message' => 'Post type registered for this request. Add to a plugin file for persistence.'];
            } catch (\Throwable $e) {
                return new WP_Error('register_failed', sprintf('Failed to register post type: %s', $e->getMessage()));
            }

        case 'register_taxonomy':
            $tax_data = isset($input['taxonomy_data']) ? (array) $input['taxonomy_data'] : [];
            if (empty($tax_data['slug'])) {
                return new WP_Error('missing_slug', 'taxonomy_data.slug is required.');
            }
            $slug = sanitize_key((string) $tax_data['slug']);
            $singular = $tax_data['singular_name'] ?? ucfirst($slug);
            $plural = $tax_data['plural_name'] ?? $singular . 's';
            $post_types = $tax_data['post_types'] ?? ['post'];
            try {
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
                return ['success' => true, 'data' => ['slug' => $slug, 'post_types' => $post_types], 'message' => 'Taxonomy registered for this request. Add to a plugin file for persistence.'];
            } catch (\Throwable $e) {
                return new WP_Error('register_failed', sprintf('Failed to register taxonomy: %s', $e->getMessage()));
            }

        case 'create_relationship':
            if (!function_exists('MB_Relationships_API')) {
                // Check if the class-based API exists
                if (!class_exists('MB_Relationships_API')) {
                    return new WP_Error('mb_relationships_missing', 'MB Relationships extension is not active. Install and activate it first.');
                }
            }
            $rel_data = isset($input['relationship_data']) ? (array) $input['relationship_data'] : [];
            if (empty($rel_data['id'])) {
                return new WP_Error('missing_id', 'relationship_data.id is required.');
            }
            try {
                $args = [
                    'id' => sanitize_key((string) $rel_data['id']),
                    'from' => $rel_data['from'] ?? ['object_type' => 'post', 'post_type' => 'post'],
                    'to' => $rel_data['to'] ?? ['object_type' => 'post', 'post_type' => 'page'],
                ];
                if (isset($rel_data['reciprocal'])) {
                    $args['reciprocal'] = (bool) $rel_data['reciprocal'];
                }
                \MB_Relationships_API::register($args);
                return ['success' => true, 'data' => $args, 'message' => 'Relationship registered for this request. Add to a plugin file for persistence.'];
            } catch (\Throwable $e) {
                return new WP_Error('create_relationship_failed', sprintf('Failed to create relationship: %s', $e->getMessage()));
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
                        $values[(string) $field_id] = rwmb_meta((string) $field_id, [], $post_id);
                    }
                } else {
                    // Get all meta for the post, filtered to known rwmb fields
                    $all_meta = get_post_meta($post_id);
                    foreach ($all_meta as $key => $meta_values) {
                        if (str_starts_with($key, '_')) {
                            continue;
                        }
                        $values[$key] = rwmb_meta($key, [], $post_id);
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
                    $field_id = (string) $field_id;
                    if (function_exists('rwmb_set_meta')) {
                        rwmb_set_meta($post_id, $field_id, $value);
                        $updated[] = $field_id;
                    } else {
                        // Fallback to update_post_meta
                        $result = update_post_meta($post_id, $field_id, $value);
                        if ($result === false) {
                            $errors[] = $field_id;
                        } else {
                            $updated[] = $field_id;
                        }
                    }
                }
                $data = ['updated' => $updated];
                if (!empty($errors)) {
                    $data['failed'] = $errors;
                }
                return ['success' => true, 'data' => $data, 'message' => sprintf('Updated %d fields.', count($updated))];
            } catch (\Throwable $e) {
                return new WP_Error('update_values_failed', sprintf('Failed to update values: %s', $e->getMessage()));
            }

        default:
            return new WP_Error('invalid_action', sprintf('Unknown action: %s', $action));
    }
}
