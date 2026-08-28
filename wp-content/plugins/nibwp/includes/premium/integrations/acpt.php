<?php
declare(strict_types=1);

/**
 * Abilities: ACPT (Advanced Custom Post Types) integration.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nibwp/acpt-manage-types', [
    'label' => __('ACPT Manage Types', domain: 'nibwp'),
    'description' => __(
        'Manage ACPT post types, taxonomies, and meta groups. List and create custom post types, taxonomies, and meta field groups via the ACPT API.',
        domain: 'nibwp',
    ),
    'category' => 'custom-fields',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list_post_types', 'create_post_type', 'list_taxonomies', 'create_taxonomy', 'list_meta_groups', 'create_meta_group'],
                'description' => 'The operation to perform.',
            ],
            'post_type_data' => [
                'type' => 'object',
                'description' => 'Data for creating a post type. Required for create_post_type.',
                'properties' => [
                    'slug' => ['type' => 'string', 'description' => 'Post type slug (max 20 chars, lowercase alphanumeric and underscores).'],
                    'singular' => ['type' => 'string', 'description' => 'Singular label.'],
                    'plural' => ['type' => 'string', 'description' => 'Plural label.'],
                    'icon' => ['type' => 'string', 'description' => 'Dashicon class name.'],
                    'supports' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Supported features: title, editor, thumbnail, excerpt, etc.',
                    ],
                    'public' => ['type' => 'boolean'],
                    'show_in_rest' => ['type' => 'boolean'],
                    'has_archive' => ['type' => 'boolean'],
                ],
            ],
            'taxonomy_data' => [
                'type' => 'object',
                'description' => 'Data for creating a taxonomy. Required for create_taxonomy.',
                'properties' => [
                    'slug' => ['type' => 'string', 'description' => 'Taxonomy slug.'],
                    'singular' => ['type' => 'string', 'description' => 'Singular label.'],
                    'plural' => ['type' => 'string', 'description' => 'Plural label.'],
                    'post_types' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Post types to attach the taxonomy to.',
                    ],
                    'hierarchical' => ['type' => 'boolean'],
                    'public' => ['type' => 'boolean'],
                    'show_in_rest' => ['type' => 'boolean'],
                ],
            ],
            'meta_group_data' => [
                'type' => 'object',
                'description' => 'Data for creating a meta group. Required for create_meta_group.',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Meta group name/label.'],
                    'post_type' => ['type' => 'string', 'description' => 'Post type this meta group belongs to.'],
                    'fields' => [
                        'type' => 'array',
                        'description' => 'Array of meta field definitions.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string', 'description' => 'Field name/key.'],
                                'label' => ['type' => 'string', 'description' => 'Field display label.'],
                                'type' => ['type' => 'string', 'description' => 'Field type: Text, Textarea, Editor, Number, Email, Url, Select, Checkbox, Radio, Date, File, Image, Gallery, Color, etc.'],
                                'required' => ['type' => 'boolean'],
                                'default_value' => ['type' => 'string'],
                                'options' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'object'],
                                    'description' => 'Options for Select/Radio/Checkbox fields.',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'post_type_slug' => [
                'type' => 'string',
                'description' => 'Post type slug for filtering meta groups in list_meta_groups.',
            ],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'data' => ['description' => 'Post types, taxonomies, meta groups, or operation result.'],
            'message' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => 'nibwp_acpt_manage_types',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage ACPT (Advanced Custom Post Types) post types, taxonomies, and meta groups.',
                '',
                'ACTIONS:',
                '- list_post_types: Lists all ACPT-registered custom post types.',
                '- create_post_type: Creates a new custom post type. Requires post_type_data with slug, singular, plural.',
                '- list_taxonomies: Lists all ACPT-registered taxonomies.',
                '- create_taxonomy: Creates a new taxonomy. Requires taxonomy_data with slug, singular, plural.',
                '- list_meta_groups: Lists meta groups. Optionally filter by post_type_slug.',
                '- create_meta_group: Creates a new meta group with fields. Requires meta_group_data with name, post_type, and fields.',
                '',
                'ACPT FIELD TYPES:',
                'Text, Textarea, Editor (WYSIWYG), Number, Email, Url, Select, Checkbox, Radio,',
                'Date, DateTime, Time, File, Image, Gallery, Color, Range, Toggle, Repeater, Flexible.',
                '',
                'IMPORTANT:',
                '- ACPT plugin must be active (ACPT_PLUGIN_VERSION constant must be defined).',
                '- ACPT uses its own database tables for storing CPT and field configurations.',
                '- Changes are persistent and take effect immediately.',
                '- The ACPT API may vary between versions; operations use try/catch for safety.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Execute ACPT management operations.
 *
 * @param array $input Input parameters.
 * @return array|WP_Error
 */
function nibwp_acpt_manage_types(array $input): array|WP_Error
{
    if (!defined('ACPT_PLUGIN_VERSION')) {
        return new WP_Error(
            'acpt_not_active',
            'ACPT (Advanced Custom Post Types) plugin is not active. Please install and activate ACPT.',
        );
    }

    $action = (string) $input['action'];

    switch ($action) {
        case 'list_post_types':
            try {
                // ACPT stores post types in its own DB table. Try the API class first.
                if (class_exists('\ACPT\Core\Repository\CustomPostTypeRepository')) {
                    $post_types = \ACPT\Core\Repository\CustomPostTypeRepository::get([]);
                    $result = [];
                    foreach ($post_types as $pt) {
                        $result[] = [
                            'slug' => method_exists($pt, 'getName') ? $pt->getName() : ($pt->name ?? ''),
                            'singular' => method_exists($pt, 'getSingular') ? $pt->getSingular() : ($pt->singular ?? ''),
                            'plural' => method_exists($pt, 'getPlural') ? $pt->getPlural() : ($pt->plural ?? ''),
                            'icon' => method_exists($pt, 'getIcon') ? $pt->getIcon() : ($pt->icon ?? ''),
                            'public' => method_exists($pt, 'isPublic') ? $pt->isPublic() : ($pt->public ?? true),
                        ];
                    }
                    return ['success' => true, 'data' => $result, 'message' => sprintf('Found %d ACPT post types.', count($result))];
                }

                // Fallback: query the ACPT database table directly
                global $wpdb;
                $table = $wpdb->prefix . 'acpt_custom_post_type';
                if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
                    $rows = $wpdb->get_results("SELECT * FROM `{$table}` ORDER BY `post_name` ASC", ARRAY_A);
                    return ['success' => true, 'data' => $rows ?: [], 'message' => sprintf('Found %d ACPT post types (from DB).', count($rows ?: []))];
                }

                return new WP_Error('acpt_api_unavailable', 'Cannot access ACPT post types. The ACPT API structure may differ in this version.');
            } catch (\Throwable $e) {
                return new WP_Error('list_post_types_failed', sprintf('Failed to list post types: %s', $e->getMessage()));
            }

        case 'create_post_type':
            $pt_data = isset($input['post_type_data']) ? (array) $input['post_type_data'] : [];
            if (empty($pt_data['slug']) || empty($pt_data['singular']) || empty($pt_data['plural'])) {
                return new WP_Error('missing_post_type_data', 'post_type_data.slug, singular, and plural are required.');
            }
            $slug = sanitize_key((string) $pt_data['slug']);
            if (strlen($slug) > 20) {
                return new WP_Error('slug_too_long', 'Post type slug must be 20 characters or fewer.');
            }
            if (post_type_exists($slug)) {
                return new WP_Error('post_type_exists', sprintf('Post type already exists: %s', $slug));
            }
            try {
                if (class_exists('\ACPT\Core\Repository\CustomPostTypeRepository') && method_exists('\ACPT\Core\Repository\CustomPostTypeRepository', 'save')) {
                    // Use ACPT's repository to persist the post type
                    if (class_exists('\ACPT\Core\Models\CustomPostTypeModel')) {
                        $model = \ACPT\Core\Models\CustomPostTypeModel::hydrateFromArray([
                            'name' => $slug,
                            'singular' => (string) $pt_data['singular'],
                            'plural' => (string) $pt_data['plural'],
                            'icon' => $pt_data['icon'] ?? 'dashicons-admin-post',
                            'supports' => $pt_data['supports'] ?? ['title', 'editor', 'thumbnail'],
                            'public' => $pt_data['public'] ?? true,
                            'showInRest' => $pt_data['show_in_rest'] ?? true,
                            'hasArchive' => $pt_data['has_archive'] ?? true,
                        ]);
                        \ACPT\Core\Repository\CustomPostTypeRepository::save($model);
                        return ['success' => true, 'data' => ['slug' => $slug], 'message' => 'Post type created via ACPT.'];
                    }
                }

                // Fallback: register via WordPress core and note it's not persisted in ACPT
                $args = [
                    'labels' => [
                        'name' => (string) $pt_data['plural'],
                        'singular_name' => (string) $pt_data['singular'],
                    ],
                    'public' => $pt_data['public'] ?? true,
                    'has_archive' => $pt_data['has_archive'] ?? true,
                    'show_in_rest' => $pt_data['show_in_rest'] ?? true,
                    'supports' => $pt_data['supports'] ?? ['title', 'editor', 'thumbnail'],
                    'menu_icon' => $pt_data['icon'] ?? 'dashicons-admin-post',
                ];
                $result = register_post_type($slug, $args);
                if (is_wp_error($result)) {
                    return $result;
                }
                return [
                    'success' => true,
                    'data' => ['slug' => $slug],
                    'message' => 'Post type registered via WP core (ACPT API not fully accessible). It is active for this request but may not appear in ACPT admin.',
                ];
            } catch (\Throwable $e) {
                return new WP_Error('create_post_type_failed', sprintf('Failed to create post type: %s', $e->getMessage()));
            }

        case 'list_taxonomies':
            try {
                if (class_exists('\ACPT\Core\Repository\TaxonomyRepository')) {
                    $taxonomies = \ACPT\Core\Repository\TaxonomyRepository::get([]);
                    $result = [];
                    foreach ($taxonomies as $tax) {
                        $result[] = [
                            'slug' => method_exists($tax, 'getSlug') ? $tax->getSlug() : ($tax->slug ?? ''),
                            'singular' => method_exists($tax, 'getSingular') ? $tax->getSingular() : ($tax->singular ?? ''),
                            'plural' => method_exists($tax, 'getPlural') ? $tax->getPlural() : ($tax->plural ?? ''),
                            'post_types' => method_exists($tax, 'getPostTypes') ? $tax->getPostTypes() : ($tax->post_types ?? []),
                        ];
                    }
                    return ['success' => true, 'data' => $result, 'message' => sprintf('Found %d ACPT taxonomies.', count($result))];
                }

                // Fallback: query DB
                global $wpdb;
                $table = $wpdb->prefix . 'acpt_taxonomy';
                if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
                    $rows = $wpdb->get_results("SELECT * FROM `{$table}` ORDER BY `slug` ASC", ARRAY_A);
                    return ['success' => true, 'data' => $rows ?: [], 'message' => sprintf('Found %d ACPT taxonomies (from DB).', count($rows ?: []))];
                }

                return new WP_Error('acpt_api_unavailable', 'Cannot access ACPT taxonomies.');
            } catch (\Throwable $e) {
                return new WP_Error('list_taxonomies_failed', sprintf('Failed to list taxonomies: %s', $e->getMessage()));
            }

        case 'create_taxonomy':
            $tax_data = isset($input['taxonomy_data']) ? (array) $input['taxonomy_data'] : [];
            if (empty($tax_data['slug']) || empty($tax_data['singular']) || empty($tax_data['plural'])) {
                return new WP_Error('missing_taxonomy_data', 'taxonomy_data.slug, singular, and plural are required.');
            }
            $slug = sanitize_key((string) $tax_data['slug']);
            if (taxonomy_exists($slug)) {
                return new WP_Error('taxonomy_exists', sprintf('Taxonomy already exists: %s', $slug));
            }
            try {
                if (class_exists('\ACPT\Core\Repository\TaxonomyRepository') && method_exists('\ACPT\Core\Repository\TaxonomyRepository', 'save')) {
                    if (class_exists('\ACPT\Core\Models\TaxonomyModel')) {
                        $model = \ACPT\Core\Models\TaxonomyModel::hydrateFromArray([
                            'slug' => $slug,
                            'singular' => (string) $tax_data['singular'],
                            'plural' => (string) $tax_data['plural'],
                            'postTypes' => $tax_data['post_types'] ?? [],
                            'hierarchical' => $tax_data['hierarchical'] ?? true,
                            'public' => $tax_data['public'] ?? true,
                            'showInRest' => $tax_data['show_in_rest'] ?? true,
                        ]);
                        \ACPT\Core\Repository\TaxonomyRepository::save($model);
                        return ['success' => true, 'data' => ['slug' => $slug], 'message' => 'Taxonomy created via ACPT.'];
                    }
                }

                // Fallback: register via WordPress core
                $post_types = $tax_data['post_types'] ?? ['post'];
                $args = [
                    'labels' => [
                        'name' => (string) $tax_data['plural'],
                        'singular_name' => (string) $tax_data['singular'],
                    ],
                    'public' => $tax_data['public'] ?? true,
                    'hierarchical' => $tax_data['hierarchical'] ?? true,
                    'show_in_rest' => $tax_data['show_in_rest'] ?? true,
                ];
                $result = register_taxonomy($slug, $post_types, $args);
                if (is_wp_error($result)) {
                    return $result;
                }
                return [
                    'success' => true,
                    'data' => ['slug' => $slug],
                    'message' => 'Taxonomy registered via WP core (ACPT API not fully accessible). Active for this request only.',
                ];
            } catch (\Throwable $e) {
                return new WP_Error('create_taxonomy_failed', sprintf('Failed to create taxonomy: %s', $e->getMessage()));
            }

        case 'list_meta_groups':
            $post_type_slug = isset($input['post_type_slug']) ? (string) $input['post_type_slug'] : '';
            try {
                if (class_exists('\ACPT\Core\Repository\MetaRepository')) {
                    $params = $post_type_slug !== '' ? ['postType' => $post_type_slug] : [];
                    $groups = \ACPT\Core\Repository\MetaRepository::get($params);
                    $result = [];
                    foreach ($groups as $group) {
                        $fields = [];
                        $group_fields = method_exists($group, 'getFields') ? $group->getFields() : [];
                        foreach ($group_fields as $field) {
                            $fields[] = [
                                'name' => method_exists($field, 'getName') ? $field->getName() : '',
                                'type' => method_exists($field, 'getType') ? $field->getType() : '',
                                'required' => method_exists($field, 'isRequired') ? $field->isRequired() : false,
                            ];
                        }
                        $result[] = [
                            'name' => method_exists($group, 'getName') ? $group->getName() : '',
                            'post_type' => method_exists($group, 'getPostType') ? $group->getPostType() : '',
                            'fields' => $fields,
                        ];
                    }
                    return ['success' => true, 'data' => $result, 'message' => sprintf('Found %d meta groups.', count($result))];
                }

                // Fallback: query DB
                global $wpdb;
                $table = $wpdb->prefix . 'acpt_meta_group';
                if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
                    $where = $post_type_slug !== '' ? $wpdb->prepare(" WHERE `post_type` = %s", $post_type_slug) : '';
                    $rows = $wpdb->get_results("SELECT * FROM `{$table}`{$where} ORDER BY `group_name` ASC", ARRAY_A);
                    return ['success' => true, 'data' => $rows ?: [], 'message' => sprintf('Found %d meta groups (from DB).', count($rows ?: []))];
                }

                return new WP_Error('acpt_api_unavailable', 'Cannot access ACPT meta groups.');
            } catch (\Throwable $e) {
                return new WP_Error('list_meta_groups_failed', sprintf('Failed to list meta groups: %s', $e->getMessage()));
            }

        case 'create_meta_group':
            $mg_data = isset($input['meta_group_data']) ? (array) $input['meta_group_data'] : [];
            if (empty($mg_data['name']) || empty($mg_data['post_type'])) {
                return new WP_Error('missing_meta_group_data', 'meta_group_data.name and meta_group_data.post_type are required.');
            }
            try {
                if (class_exists('\ACPT\Core\Repository\MetaRepository') && method_exists('\ACPT\Core\Repository\MetaRepository', 'save')) {
                    if (class_exists('\ACPT\Core\Models\MetaGroupModel') && class_exists('\ACPT\Core\Models\MetaFieldModel')) {
                        $field_models = [];
                        foreach (($mg_data['fields'] ?? []) as $field_def) {
                            $field_models[] = \ACPT\Core\Models\MetaFieldModel::hydrateFromArray([
                                'name' => $field_def['name'] ?? '',
                                'label' => $field_def['label'] ?? ($field_def['name'] ?? ''),
                                'type' => $field_def['type'] ?? 'Text',
                                'required' => $field_def['required'] ?? false,
                                'defaultValue' => $field_def['default_value'] ?? '',
                                'options' => $field_def['options'] ?? [],
                            ]);
                        }
                        $model = \ACPT\Core\Models\MetaGroupModel::hydrateFromArray([
                            'name' => (string) $mg_data['name'],
                            'postType' => (string) $mg_data['post_type'],
                            'fields' => $field_models,
                        ]);
                        \ACPT\Core\Repository\MetaRepository::save($model);
                        return [
                            'success' => true,
                            'data' => ['name' => (string) $mg_data['name'], 'post_type' => (string) $mg_data['post_type'], 'field_count' => count($field_models)],
                            'message' => 'Meta group created via ACPT.',
                        ];
                    }
                }

                return new WP_Error(
                    'acpt_api_unavailable',
                    'Cannot create meta group. The ACPT Model/Repository API is not accessible in this version. Use the ACPT admin UI or execute-php ability to interact with ACPT directly.',
                );
            } catch (\Throwable $e) {
                return new WP_Error('create_meta_group_failed', sprintf('Failed to create meta group: %s', $e->getMessage()));
            }

        default:
            return new WP_Error('invalid_action', sprintf('Unknown action: %s', $action));
    }
}
