<?php
declare(strict_types=1);

/**
 * Abilities: Pods integration.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nibwp/pods-manage-content', [
    'label' => __('Pods Manage Content', domain: 'nibwp'),
    'description' => __(
        'Manage Pods content types, fields, and items. List, create, and modify Pods, add fields to them, and perform CRUD operations on Pod items.',
        domain: 'nibwp',
    ),
    'category' => 'custom-fields',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list_pods', 'get_pod', 'create_pod', 'add_field', 'list_items', 'create_item', 'update_item'],
                'description' => 'The operation to perform.',
            ],
            'pod_name' => [
                'type' => 'string',
                'description' => 'The Pod name/slug. Required for get_pod, add_field, list_items, create_item, update_item.',
            ],
            'pod_data' => [
                'type' => 'object',
                'description' => 'Data for creating a Pod. Required for create_pod.',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Pod name/slug.'],
                    'label' => ['type' => 'string', 'description' => 'Pod display label.'],
                    'type' => [
                        'type' => 'string',
                        'enum' => ['post_type', 'taxonomy', 'settings', 'pod'],
                        'description' => 'Pod storage type. post_type and taxonomy extend existing WP types; pod creates an Advanced Content Type with its own DB table; settings creates an options page.',
                    ],
                    'storage' => [
                        'type' => 'string',
                        'enum' => ['meta', 'table', 'none'],
                        'description' => 'Storage type. meta uses WP post_meta, table uses a custom DB table.',
                    ],
                    'public' => ['type' => 'boolean'],
                    'show_in_rest' => ['type' => 'boolean'],
                    'has_archive' => ['type' => 'boolean'],
                    'supports' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
            ],
            'field_data' => [
                'type' => 'object',
                'description' => 'Field definition for add_field.',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Field name/slug.'],
                    'label' => ['type' => 'string', 'description' => 'Field display label.'],
                    'type' => [
                        'type' => 'string',
                        'description' => 'Field type: text, paragraph, wysiwyg, number, currency, date, datetime, time, email, phone, website, color, boolean, file, avatar, oembed, pick (relationship), etc.',
                    ],
                    'required' => ['type' => 'boolean'],
                    'description' => ['type' => 'string', 'description' => 'Field help text.'],
                    'options' => [
                        'type' => 'object',
                        'description' => 'Additional field-type-specific options.',
                        'additionalProperties' => true,
                    ],
                ],
            ],
            'item_id' => [
                'type' => 'integer',
                'description' => 'Item ID for update_item.',
            ],
            'item_data' => [
                'type' => 'object',
                'description' => 'Key-value pairs for create_item and update_item.',
                'additionalProperties' => true,
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Number of items to return for list_items. Default 20.',
            ],
            'where' => [
                'type' => 'string',
                'description' => 'WHERE clause for list_items (e.g. "t.post_status = publish").',
            ],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'data' => ['description' => 'Pods, fields, items, or operation result.'],
            'message' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => 'nibwp_pods_manage_content',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage Pods Framework content types, fields, and items.',
                '',
                'ACTIONS:',
                '- list_pods: Lists all registered Pods with their types and field counts.',
                '- get_pod: Gets a single Pod definition with all its fields. Requires pod_name.',
                '- create_pod: Creates a new Pod. Requires pod_data with name and type.',
                '  Types: post_type (extends WP CPT), taxonomy (extends WP tax),',
                '  pod (Advanced Content Type with own DB table), settings (options page).',
                '- add_field: Adds a field to an existing Pod. Requires pod_name and field_data.',
                '- list_items: Lists items from a Pod. Requires pod_name. Optional limit and where clause.',
                '- create_item: Creates a new item. Requires pod_name and item_data.',
                '- update_item: Updates an existing item. Requires pod_name, item_id, and item_data.',
                '',
                'FIELD TYPES:',
                'text, paragraph, wysiwyg, number, currency, date, datetime, time,',
                'email, phone, website, color, boolean, file, avatar, oembed,',
                'pick (relationship to other pods/post types/taxonomies/users).',
                '',
                'IMPORTANT:',
                '- Pods plugin must be active.',
                '- create_pod and add_field make persistent changes to the database.',
                '- For pick (relationship) fields, set options.pick_object and options.pick_val.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Execute Pods content management operations.
 *
 * @param array $input Input parameters.
 * @return array|WP_Error
 */
function nibwp_pods_manage_content(array $input): array|WP_Error
{
    if (!function_exists('pods')) {
        return new WP_Error(
            'pods_not_active',
            'Pods plugin is not active. Please install and activate Pods.',
        );
    }

    $action = (string) $input['action'];
    $pod_name = isset($input['pod_name']) ? (string) $input['pod_name'] : '';

    switch ($action) {
        case 'list_pods':
            try {
                $api = pods_api();
                $all_pods = $api->load_pods(['names' => false]);
                $result = [];
                foreach ($all_pods as $pod) {
                    $result[] = [
                        'name' => $pod['name'] ?? '',
                        'label' => $pod['label'] ?? '',
                        'type' => $pod['type'] ?? '',
                        'storage' => $pod['storage'] ?? '',
                        'field_count' => count($pod['fields'] ?? []),
                        'id' => $pod['id'] ?? 0,
                    ];
                }
                return ['success' => true, 'data' => $result, 'message' => sprintf('Found %d Pods.', count($result))];
            } catch (\Throwable $e) {
                return new WP_Error('list_pods_failed', sprintf('Failed to list Pods: %s', $e->getMessage()));
            }

        case 'get_pod':
            if ($pod_name === '') {
                return new WP_Error('missing_pod_name', 'pod_name is required for get_pod action.');
            }
            try {
                $api = pods_api();
                $pod = $api->load_pod(['name' => $pod_name]);
                if (empty($pod)) {
                    return new WP_Error('pod_not_found', sprintf('Pod not found: %s', $pod_name));
                }
                $fields = [];
                foreach (($pod['fields'] ?? []) as $field) {
                    $fields[] = [
                        'name' => $field['name'] ?? '',
                        'label' => $field['label'] ?? '',
                        'type' => $field['type'] ?? '',
                        'required' => (bool) ($field['required'] ?? false),
                        'id' => $field['id'] ?? 0,
                    ];
                }
                return [
                    'success' => true,
                    'data' => [
                        'name' => $pod['name'] ?? '',
                        'label' => $pod['label'] ?? '',
                        'type' => $pod['type'] ?? '',
                        'storage' => $pod['storage'] ?? '',
                        'fields' => $fields,
                    ],
                    'message' => 'Pod retrieved.',
                ];
            } catch (\Throwable $e) {
                return new WP_Error('get_pod_failed', sprintf('Failed to get Pod: %s', $e->getMessage()));
            }

        case 'create_pod':
            $pod_data = isset($input['pod_data']) ? (array) $input['pod_data'] : [];
            if (empty($pod_data['name'])) {
                return new WP_Error('missing_name', 'pod_data.name is required for create_pod action.');
            }
            if (empty($pod_data['type'])) {
                return new WP_Error('missing_type', 'pod_data.type is required for create_pod action.');
            }
            try {
                $api = pods_api();
                $params = [
                    'name' => sanitize_key((string) $pod_data['name']),
                    'label' => $pod_data['label'] ?? ucfirst((string) $pod_data['name']),
                    'type' => (string) $pod_data['type'],
                ];
                if (isset($pod_data['storage'])) {
                    $params['storage'] = (string) $pod_data['storage'];
                }
                $options = [];
                if (isset($pod_data['public'])) {
                    $options['public'] = (bool) $pod_data['public'];
                }
                if (isset($pod_data['show_in_rest'])) {
                    $options['show_in_rest'] = (bool) $pod_data['show_in_rest'];
                }
                if (isset($pod_data['has_archive'])) {
                    $options['has_archive'] = (bool) $pod_data['has_archive'];
                }
                if (isset($pod_data['supports'])) {
                    $options['supports'] = (array) $pod_data['supports'];
                }
                $params['options'] = $options;

                $pod_id = $api->save_pod($params);
                if (empty($pod_id) || is_wp_error($pod_id)) {
                    return is_wp_error($pod_id) ? $pod_id : new WP_Error('create_failed', 'Failed to create Pod.');
                }
                return ['success' => true, 'data' => ['id' => $pod_id, 'name' => $params['name']], 'message' => 'Pod created.'];
            } catch (\Throwable $e) {
                return new WP_Error('create_pod_failed', sprintf('Failed to create Pod: %s', $e->getMessage()));
            }

        case 'add_field':
            if ($pod_name === '') {
                return new WP_Error('missing_pod_name', 'pod_name is required for add_field action.');
            }
            $field_data = isset($input['field_data']) ? (array) $input['field_data'] : [];
            if (empty($field_data['name']) || empty($field_data['type'])) {
                return new WP_Error('missing_field_data', 'field_data.name and field_data.type are required.');
            }
            try {
                $api = pods_api();
                $params = [
                    'pod' => $pod_name,
                    'name' => sanitize_key((string) $field_data['name']),
                    'label' => $field_data['label'] ?? ucfirst((string) $field_data['name']),
                    'type' => (string) $field_data['type'],
                ];
                if (isset($field_data['required'])) {
                    $params['required'] = (bool) $field_data['required'] ? '1' : '0';
                }
                if (isset($field_data['description'])) {
                    $params['description'] = (string) $field_data['description'];
                }
                if (isset($field_data['options'])) {
                    $params = array_merge($params, (array) $field_data['options']);
                }
                $field_id = $api->save_field($params);
                if (empty($field_id) || is_wp_error($field_id)) {
                    return is_wp_error($field_id) ? $field_id : new WP_Error('add_field_failed', 'Failed to add field.');
                }
                return ['success' => true, 'data' => ['id' => $field_id, 'name' => $params['name']], 'message' => 'Field added to Pod.'];
            } catch (\Throwable $e) {
                return new WP_Error('add_field_failed', sprintf('Failed to add field: %s', $e->getMessage()));
            }

        case 'list_items':
            if ($pod_name === '') {
                return new WP_Error('missing_pod_name', 'pod_name is required for list_items action.');
            }
            try {
                $limit = isset($input['limit']) ? (int) $input['limit'] : 20;
                $limit = max(1, min($limit, 200));
                $params = ['limit' => $limit];
                if (!empty($input['where'])) {
                    $params['where'] = (string) $input['where'];
                }
                $pod = pods($pod_name, $params);
                if (!$pod || !method_exists($pod, 'total_found')) {
                    return new WP_Error('pod_not_found', sprintf('Pod not found: %s', $pod_name));
                }
                $items = [];
                if ($pod->total() > 0) {
                    while ($pod->fetch()) {
                        $row = $pod->row();
                        $items[] = $row;
                    }
                }
                return ['success' => true, 'data' => $items, 'message' => sprintf('Retrieved %d items (total: %d).', count($items), $pod->total_found())];
            } catch (\Throwable $e) {
                return new WP_Error('list_items_failed', sprintf('Failed to list items: %s', $e->getMessage()));
            }

        case 'create_item':
            if ($pod_name === '') {
                return new WP_Error('missing_pod_name', 'pod_name is required for create_item action.');
            }
            $item_data = isset($input['item_data']) ? (array) $input['item_data'] : [];
            if (empty($item_data)) {
                return new WP_Error('missing_item_data', 'item_data is required for create_item action.');
            }
            try {
                $pod = pods($pod_name);
                if (!$pod || !method_exists($pod, 'add')) {
                    return new WP_Error('pod_not_found', sprintf('Pod not found: %s', $pod_name));
                }
                $item_id = $pod->add($item_data);
                if (empty($item_id) || is_wp_error($item_id)) {
                    return is_wp_error($item_id) ? $item_id : new WP_Error('create_item_failed', 'Failed to create item.');
                }
                return ['success' => true, 'data' => ['item_id' => $item_id], 'message' => 'Item created.'];
            } catch (\Throwable $e) {
                return new WP_Error('create_item_failed', sprintf('Failed to create item: %s', $e->getMessage()));
            }

        case 'update_item':
            if ($pod_name === '') {
                return new WP_Error('missing_pod_name', 'pod_name is required for update_item action.');
            }
            $item_id = isset($input['item_id']) ? (int) $input['item_id'] : 0;
            $item_data = isset($input['item_data']) ? (array) $input['item_data'] : [];
            if ($item_id <= 0) {
                return new WP_Error('missing_item_id', 'item_id is required for update_item action.');
            }
            if (empty($item_data)) {
                return new WP_Error('missing_item_data', 'item_data is required for update_item action.');
            }
            try {
                $pod = pods($pod_name, $item_id);
                if (!$pod || !$pod->exists()) {
                    return new WP_Error('item_not_found', sprintf('Item %d not found in Pod %s.', $item_id, $pod_name));
                }
                $saved_id = $pod->save($item_data, null, $item_id);
                if (empty($saved_id) || is_wp_error($saved_id)) {
                    return is_wp_error($saved_id) ? $saved_id : new WP_Error('update_item_failed', 'Failed to update item.');
                }
                return ['success' => true, 'data' => ['item_id' => $saved_id], 'message' => 'Item updated.'];
            } catch (\Throwable $e) {
                return new WP_Error('update_item_failed', sprintf('Failed to update item: %s', $e->getMessage()));
            }

        default:
            return new WP_Error('invalid_action', sprintf('Unknown action: %s', $action));
    }
}
