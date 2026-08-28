<?php
declare(strict_types=1);

/**
 * Abilities: JetEngine integration.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nibwp/jetengine-manage-fields', [
    'label' => __('JetEngine Manage Fields', domain: 'nibwp'),
    'description' => __(
        'Manage JetEngine meta boxes and Custom Content Types (CCT). List and create meta boxes, list and create CCTs, and manage CCT items.',
        domain: 'nibwp',
    ),
    'category' => 'custom-fields',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list_meta_boxes', 'create_meta_box', 'list_cct', 'create_cct', 'get_cct_items', 'create_cct_item'],
                'description' => 'The operation to perform.',
            ],
            'meta_box_data' => [
                'type' => 'object',
                'description' => 'Data for creating a meta box. Required for create_meta_box.',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Meta box display name.'],
                    'slug' => ['type' => 'string', 'description' => 'Meta box slug/identifier.'],
                    'object_type' => ['type' => 'string', 'description' => 'Object type: post, taxonomy, user, etc.'],
                    'allowed_post_type' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Post types to attach to (when object_type is post).',
                    ],
                    'meta_fields' => [
                        'type' => 'array',
                        'description' => 'Array of field definitions.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'title' => ['type' => 'string'],
                                'type' => ['type' => 'string', 'description' => 'Field type: text, textarea, select, checkbox, etc.'],
                                'options' => ['type' => 'array', 'items' => ['type' => 'object']],
                            ],
                        ],
                    ],
                ],
            ],
            'cct_data' => [
                'type' => 'object',
                'description' => 'Data for creating a Custom Content Type. Required for create_cct.',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'CCT display name.'],
                    'slug' => ['type' => 'string', 'description' => 'CCT slug (used for DB table name).'],
                    'args' => [
                        'type' => 'object',
                        'description' => 'Additional CCT arguments (has_single, show_in_menu, etc.).',
                    ],
                    'meta_fields' => [
                        'type' => 'array',
                        'description' => 'Field definitions for the CCT.',
                        'items' => ['type' => 'object'],
                    ],
                ],
            ],
            'cct_slug' => [
                'type' => 'string',
                'description' => 'CCT slug for get_cct_items and create_cct_item actions.',
            ],
            'item_data' => [
                'type' => 'object',
                'description' => 'Item data for create_cct_item. Key-value pairs matching CCT fields.',
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
            'data' => ['description' => 'Meta boxes, CCTs, CCT items, or creation result.'],
            'message' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => 'nibwp_jetengine_manage_fields',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage JetEngine meta boxes and Custom Content Types.',
                '',
                'ACTIONS:',
                '- list_meta_boxes: Lists all registered JetEngine meta boxes.',
                '- create_meta_box: Creates a new meta box. Requires meta_box_data with name, slug, object_type, and meta_fields.',
                '- list_cct: Lists all Custom Content Types.',
                '- create_cct: Creates a new CCT. Requires cct_data with name, slug, and optionally meta_fields.',
                '- get_cct_items: Retrieves items from a CCT. Requires cct_slug.',
                '- create_cct_item: Creates a new item in a CCT. Requires cct_slug and item_data.',
                '',
                'META FIELD TYPES:',
                'Common types: text, textarea, wysiwyg, number, date, time, datetime,',
                'select, checkbox, radio, switcher, media, gallery, repeater, html.',
                '',
                'IMPORTANT:',
                '- JetEngine plugin must be active.',
                '- CCT module must be enabled in JetEngine settings for CCT operations.',
                '- Meta boxes are stored in the jet_post_types DB table via JetEngine internal API.',
                '- Changes via create operations are persistent and take effect immediately.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Execute JetEngine field management operations.
 *
 * @param array $input Input parameters.
 * @return array|WP_Error
 */
function nibwp_jetengine_manage_fields(array $input): array|WP_Error
{
    if (!function_exists('jet_engine')) {
        return new WP_Error(
            'jetengine_not_active',
            'JetEngine plugin is not active. Please install and activate JetEngine.',
        );
    }

    $action = (string) $input['action'];

    switch ($action) {
        case 'list_meta_boxes':
            try {
                $meta_boxes_module = jet_engine()->meta_boxes;
                if (!$meta_boxes_module || !is_object($meta_boxes_module)) {
                    return new WP_Error('meta_boxes_unavailable', 'JetEngine meta boxes module is not available.');
                }
                $raw = $meta_boxes_module->data->get_items();
                $result = [];
                foreach ($raw as $item) {
                    $args = maybe_unserialize($item['meta_fields'] ?? $item['args'] ?? '');
                    $result[] = [
                        'id' => $item['id'] ?? 0,
                        'slug' => $item['slug'] ?? '',
                        'name' => $item['labels']['name'] ?? $item['name'] ?? '',
                        'object_type' => $item['args']['object_type'] ?? 'post',
                        'field_count' => is_array($args) ? count($args) : 0,
                    ];
                }
                return ['success' => true, 'data' => $result, 'message' => sprintf('Found %d meta boxes.', count($result))];
            } catch (\Throwable $e) {
                return new WP_Error('list_meta_boxes_failed', sprintf('Failed to list meta boxes: %s', $e->getMessage()));
            }

        case 'create_meta_box':
            $meta_box_data = isset($input['meta_box_data']) ? (array) $input['meta_box_data'] : [];
            if (empty($meta_box_data['name']) || empty($meta_box_data['slug'])) {
                return new WP_Error('missing_meta_box_data', 'meta_box_data.name and meta_box_data.slug are required.');
            }
            try {
                $meta_boxes_module = jet_engine()->meta_boxes;
                if (!$meta_boxes_module || !is_object($meta_boxes_module)) {
                    return new WP_Error('meta_boxes_unavailable', 'JetEngine meta boxes module is not available.');
                }
                $args = [
                    'name' => (string) $meta_box_data['name'],
                    'slug' => sanitize_key((string) $meta_box_data['slug']),
                    'object_type' => $meta_box_data['object_type'] ?? 'post',
                    'allowed_post_type' => $meta_box_data['allowed_post_type'] ?? ['post'],
                    'meta_fields' => $meta_box_data['meta_fields'] ?? [],
                ];
                $item_id = $meta_boxes_module->data->update_item_in_db($args);
                if (empty($item_id)) {
                    return new WP_Error('create_failed', 'Failed to create meta box. update_item_in_db returned empty.');
                }
                return ['success' => true, 'data' => ['id' => $item_id, 'slug' => $args['slug']], 'message' => 'Meta box created.'];
            } catch (\Throwable $e) {
                return new WP_Error('create_meta_box_failed', sprintf('Failed to create meta box: %s', $e->getMessage()));
            }

        case 'list_cct':
            try {
                $cct_module = jet_engine()->modules->get_module('custom-content-types');
                if (!$cct_module) {
                    return new WP_Error('cct_not_enabled', 'Custom Content Types module is not enabled in JetEngine.');
                }
                $cct_instance = $cct_module->instance;
                if (!$cct_instance || !method_exists($cct_instance, 'get_content_types')) {
                    // Fallback: try to access via manager
                    $manager = $cct_module->instance->manager ?? null;
                    if ($manager && method_exists($manager, 'get_items')) {
                        $types = $manager->get_items();
                    } else {
                        return new WP_Error('cct_api_unavailable', 'Cannot access CCT list. The CCT API structure may differ in this JetEngine version.');
                    }
                } else {
                    $types = $cct_instance->get_content_types();
                }
                $result = [];
                if (is_array($types)) {
                    foreach ($types as $type) {
                        if (is_object($type)) {
                            $result[] = [
                                'slug' => $type->slug ?? '',
                                'name' => $type->args['name'] ?? '',
                            ];
                        } elseif (is_array($type)) {
                            $result[] = [
                                'slug' => $type['slug'] ?? '',
                                'name' => $type['name'] ?? '',
                            ];
                        }
                    }
                }
                return ['success' => true, 'data' => $result, 'message' => sprintf('Found %d custom content types.', count($result))];
            } catch (\Throwable $e) {
                return new WP_Error('list_cct_failed', sprintf('Failed to list CCTs: %s', $e->getMessage()));
            }

        case 'create_cct':
            $cct_data = isset($input['cct_data']) ? (array) $input['cct_data'] : [];
            if (empty($cct_data['name']) || empty($cct_data['slug'])) {
                return new WP_Error('missing_cct_data', 'cct_data.name and cct_data.slug are required.');
            }
            try {
                $cct_module = jet_engine()->modules->get_module('custom-content-types');
                if (!$cct_module) {
                    return new WP_Error('cct_not_enabled', 'Custom Content Types module is not enabled in JetEngine.');
                }
                $args = [
                    'name' => (string) $cct_data['name'],
                    'slug' => sanitize_key((string) $cct_data['slug']),
                    'args' => $cct_data['args'] ?? [],
                    'meta_fields' => $cct_data['meta_fields'] ?? [],
                ];
                $data_handler = $cct_module->instance->manager ?? null;
                if ($data_handler && method_exists($data_handler, 'update_item_in_db')) {
                    $item_id = $data_handler->update_item_in_db($args);
                } else {
                    return new WP_Error('cct_api_unavailable', 'Cannot create CCT. The CCT manager API is not accessible in this JetEngine version.');
                }
                if (empty($item_id)) {
                    return new WP_Error('create_cct_failed', 'Failed to create CCT.');
                }
                return ['success' => true, 'data' => ['id' => $item_id, 'slug' => $args['slug']], 'message' => 'Custom content type created.'];
            } catch (\Throwable $e) {
                return new WP_Error('create_cct_failed', sprintf('Failed to create CCT: %s', $e->getMessage()));
            }

        case 'get_cct_items':
            $cct_slug = isset($input['cct_slug']) ? (string) $input['cct_slug'] : '';
            if ($cct_slug === '') {
                return new WP_Error('missing_cct_slug', 'cct_slug is required for get_cct_items action.');
            }
            try {
                $cct_module = jet_engine()->modules->get_module('custom-content-types');
                if (!$cct_module) {
                    return new WP_Error('cct_not_enabled', 'Custom Content Types module is not enabled in JetEngine.');
                }
                $type_object = null;
                if (method_exists($cct_module->instance, 'get_content_types')) {
                    $types = $cct_module->instance->get_content_types();
                    $type_object = $types[$cct_slug] ?? null;
                }
                if (!$type_object || !method_exists($type_object, 'get_items')) {
                    return new WP_Error('cct_not_found', sprintf('Custom content type not found: %s', $cct_slug));
                }
                $items = $type_object->get_items(['limit' => 100]);
                return ['success' => true, 'data' => $items ?: [], 'message' => sprintf('Retrieved %d items.', count($items ?: []))];
            } catch (\Throwable $e) {
                return new WP_Error('get_cct_items_failed', sprintf('Failed to get CCT items: %s', $e->getMessage()));
            }

        case 'create_cct_item':
            $cct_slug = isset($input['cct_slug']) ? (string) $input['cct_slug'] : '';
            $item_data = isset($input['item_data']) ? (array) $input['item_data'] : [];
            if ($cct_slug === '') {
                return new WP_Error('missing_cct_slug', 'cct_slug is required for create_cct_item action.');
            }
            if (empty($item_data)) {
                return new WP_Error('missing_item_data', 'item_data is required for create_cct_item action.');
            }
            try {
                $cct_module = jet_engine()->modules->get_module('custom-content-types');
                if (!$cct_module) {
                    return new WP_Error('cct_not_enabled', 'Custom Content Types module is not enabled in JetEngine.');
                }
                $type_object = null;
                if (method_exists($cct_module->instance, 'get_content_types')) {
                    $types = $cct_module->instance->get_content_types();
                    $type_object = $types[$cct_slug] ?? null;
                }
                if (!$type_object || !method_exists($type_object, 'insert_item')) {
                    return new WP_Error('cct_not_found', sprintf('Custom content type not found or insert not available: %s', $cct_slug));
                }
                $item_id = $type_object->insert_item($item_data);
                if (is_wp_error($item_id)) {
                    return $item_id;
                }
                return ['success' => true, 'data' => ['item_id' => $item_id], 'message' => 'CCT item created.'];
            } catch (\Throwable $e) {
                return new WP_Error('create_cct_item_failed', sprintf('Failed to create CCT item: %s', $e->getMessage()));
            }

        default:
            return new WP_Error('invalid_action', sprintf('Unknown action: %s', $action));
    }
}
