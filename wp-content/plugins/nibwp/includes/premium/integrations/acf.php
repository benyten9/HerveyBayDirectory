<?php
declare(strict_types=1);

/**
 * Abilities: Advanced Custom Fields (ACF) integration.
 */

if (!defined('ABSPATH')) {
    exit();
}

// --- Ability: nibwp/acf-manage-fields ---

wp_register_ability('nibwp/acf-manage-fields', [
    'label' => __('ACF Manage Fields', domain: 'nibwp'),
    'description' => __(
        'CRUD operations for Advanced Custom Fields field groups and fields. List, get, create, update, and delete ACF field groups including their field definitions and location rules.',
        domain: 'nibwp',
    ),
    'category' => 'custom-fields',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list_groups', 'get_group', 'create_group', 'update_group', 'delete_group'],
                'description' => 'The operation to perform on ACF field groups.',
            ],
            'group_key' => [
                'type' => 'string',
                'description' => 'The field group key or ID. Required for get_group, update_group, and delete_group actions.',
            ],
            'group_data' => [
                'type' => 'object',
                'description' => 'Field group data for create_group and update_group actions.',
                'properties' => [
                    'title' => [
                        'type' => 'string',
                        'description' => 'The field group title.',
                    ],
                    'fields' => [
                        'type' => 'array',
                        'description' => 'Array of field definitions. Each field should have at minimum: key, label, name, type.',
                        'items' => [
                            'type' => 'object',
                        ],
                    ],
                    'location' => [
                        'type' => 'array',
                        'description' => 'Location rules determining where the field group appears. Array of rule groups (OR), each containing rules (AND).',
                        'items' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'param' => ['type' => 'string'],
                                    'operator' => ['type' => 'string'],
                                    'value' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                    'menu_order' => ['type' => 'integer'],
                    'position' => ['type' => 'string', 'enum' => ['acf_after_title', 'normal', 'side']],
                    'style' => ['type' => 'string', 'enum' => ['default', 'seamless']],
                    'active' => ['type' => 'boolean'],
                ],
            ],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'data' => ['description' => 'The resulting field groups, single group with fields, or deletion confirmation.'],
            'message' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => 'nibwp_acf_manage_fields',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage ACF (Advanced Custom Fields) field groups and their fields.',
                '',
                'ACTIONS:',
                '- list_groups: Lists all registered ACF field groups with basic info.',
                '- get_group: Retrieves a single field group with all its fields. Requires group_key.',
                '- create_group: Creates a new field group. Requires group_data with at least title.',
                '- update_group: Updates an existing field group. Requires group_key and group_data.',
                '- delete_group: Deletes a field group. Requires group_key.',
                '',
                'FIELD DEFINITIONS:',
                'Each field in the fields array should include: key (unique, e.g. "field_abc123"),',
                'label (display name), name (meta key), type (text, textarea, image, select, etc.).',
                'See ACF documentation for all available field types and their settings.',
                '',
                'LOCATION RULES:',
                'Location is an array of rule groups. Groups are OR-ed, rules within a group are AND-ed.',
                'Example: [[{"param":"post_type","operator":"==","value":"post"}]]',
                '',
                'IMPORTANT: ACF plugin must be active. Field keys must be unique across all groups.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Execute ACF field group management operations.
 *
 * @param array $input Input parameters.
 * @return array|WP_Error
 */
function nibwp_acf_manage_fields(array $input): array|WP_Error
{
    if (!function_exists('acf_get_field_groups')) {
        return new WP_Error(
            'acf_not_active',
            'Advanced Custom Fields plugin is not active. Please install and activate ACF.',
        );
    }

    $action = (string) $input['action'];
    $group_key = isset($input['group_key']) ? (string) $input['group_key'] : '';
    $group_data = isset($input['group_data']) ? (array) $input['group_data'] : [];

    switch ($action) {
        case 'list_groups':
            $groups = acf_get_field_groups();
            $result = [];
            foreach ($groups as $group) {
                $result[] = [
                    'key' => $group['key'] ?? '',
                    'title' => $group['title'] ?? '',
                    'active' => (bool) ($group['active'] ?? true),
                    'menu_order' => (int) ($group['menu_order'] ?? 0),
                    'ID' => $group['ID'] ?? 0,
                ];
            }
            return ['success' => true, 'data' => $result, 'message' => sprintf('Found %d field groups.', count($result))];

        case 'get_group':
            if ($group_key === '') {
                return new WP_Error('missing_group_key', 'group_key is required for get_group action.');
            }
            $groups = acf_get_field_groups();
            $found = null;
            foreach ($groups as $group) {
                if (($group['key'] ?? '') === $group_key || (string) ($group['ID'] ?? 0) === $group_key) {
                    $found = $group;
                    break;
                }
            }
            if ($found === null) {
                return new WP_Error('group_not_found', sprintf('Field group not found: %s', $group_key));
            }
            $fields = acf_get_fields($found['key']);
            $found['fields'] = $fields ?: [];
            return ['success' => true, 'data' => $found, 'message' => 'Field group retrieved.'];

        case 'create_group':
            if (empty($group_data['title'])) {
                return new WP_Error('missing_title', 'group_data.title is required for create_group action.');
            }
            $new_group = [
                'title' => (string) $group_data['title'],
                'key' => $group_data['key'] ?? ('group_' . wp_generate_uuid4()),
                'fields' => $group_data['fields'] ?? [],
                'location' => $group_data['location'] ?? [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
                'menu_order' => (int) ($group_data['menu_order'] ?? 0),
                'position' => $group_data['position'] ?? 'normal',
                'style' => $group_data['style'] ?? 'default',
                'active' => $group_data['active'] ?? true,
            ];
            $saved = acf_update_field_group($new_group);
            if (empty($saved) || empty($saved['ID'])) {
                return new WP_Error('create_failed', 'Failed to create the field group.');
            }
            return ['success' => true, 'data' => $saved, 'message' => 'Field group created.'];

        case 'update_group':
            if ($group_key === '') {
                return new WP_Error('missing_group_key', 'group_key is required for update_group action.');
            }
            if (empty($group_data)) {
                return new WP_Error('missing_group_data', 'group_data is required for update_group action.');
            }
            $groups = acf_get_field_groups();
            $existing = null;
            foreach ($groups as $group) {
                if (($group['key'] ?? '') === $group_key || (string) ($group['ID'] ?? 0) === $group_key) {
                    $existing = $group;
                    break;
                }
            }
            if ($existing === null) {
                return new WP_Error('group_not_found', sprintf('Field group not found: %s', $group_key));
            }
            $updated = array_merge($existing, $group_data);
            $updated['key'] = $existing['key'];
            $updated['ID'] = $existing['ID'];
            $saved = acf_update_field_group($updated);
            if (empty($saved)) {
                return new WP_Error('update_failed', 'Failed to update the field group.');
            }
            return ['success' => true, 'data' => $saved, 'message' => 'Field group updated.'];

        case 'delete_group':
            if ($group_key === '') {
                return new WP_Error('missing_group_key', 'group_key is required for delete_group action.');
            }
            $groups = acf_get_field_groups();
            $found_id = null;
            foreach ($groups as $group) {
                if (($group['key'] ?? '') === $group_key || (string) ($group['ID'] ?? 0) === $group_key) {
                    $found_id = $group['key'];
                    break;
                }
            }
            if ($found_id === null) {
                return new WP_Error('group_not_found', sprintf('Field group not found: %s', $group_key));
            }
            $deleted = acf_delete_field_group($found_id);
            if (!$deleted) {
                return new WP_Error('delete_failed', 'Failed to delete the field group.');
            }
            return ['success' => true, 'data' => ['deleted_key' => $found_id], 'message' => 'Field group deleted.'];

        default:
            return new WP_Error('invalid_action', sprintf('Unknown action: %s', $action));
    }
}

// --- Ability: nibwp/acf-manage-options ---

wp_register_ability('nibwp/acf-manage-options', [
    'label' => __('ACF Manage Options', domain: 'nibwp'),
    'description' => __(
        'Manage ACF options pages and their field values. List existing options pages, create new ones, retrieve option values, and update them.',
        domain: 'nibwp',
    ),
    'category' => 'custom-fields',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list_pages', 'create_page', 'get_values', 'update_values'],
                'description' => 'The operation to perform.',
            ],
            'page_slug' => [
                'type' => 'string',
                'description' => 'The options page slug. Required for get_values and update_values.',
            ],
            'page_data' => [
                'type' => 'object',
                'description' => 'Data for creating an options page.',
                'properties' => [
                    'page_title' => ['type' => 'string', 'description' => 'The page title displayed in the admin.'],
                    'menu_title' => ['type' => 'string', 'description' => 'The menu title. Defaults to page_title.'],
                    'menu_slug' => ['type' => 'string', 'description' => 'Unique menu slug.'],
                    'capability' => ['type' => 'string', 'description' => 'Required capability. Default: edit_posts.'],
                    'parent_slug' => ['type' => 'string', 'description' => 'Parent menu slug for sub-pages.'],
                    'icon_url' => ['type' => 'string'],
                    'position' => ['type' => 'integer'],
                    'redirect' => ['type' => 'boolean'],
                    'autoload' => ['type' => 'boolean'],
                ],
            ],
            'values' => [
                'type' => 'object',
                'description' => 'Key-value pairs to update. Keys are ACF field names, values are the new values.',
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
            'data' => ['description' => 'Options pages list, page data, field values, or update results.'],
            'message' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => 'nibwp_acf_manage_options',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage ACF options pages and their stored values.',
                '',
                'ACTIONS:',
                '- list_pages: Lists all registered ACF options pages.',
                '- create_page: Creates a new options page. Requires page_data with at least page_title.',
                '- get_values: Retrieves all field values for an options page. Requires page_slug.',
                '  Values are fetched from the "option" post_id context using the page slug.',
                '- update_values: Updates field values on an options page. Requires page_slug and values object.',
                '',
                'NOTES:',
                '- ACF Pro is required for options pages functionality.',
                '- The page_slug for get_values/update_values corresponds to the post_id used by ACF.',
                '  For the default options page, use "options". For sub-pages, use the menu_slug.',
                '- When updating values, each key should be an ACF field name (not field key).',
                '- create_page only registers the page in the current request; for persistence,',
                '  the registration code should be in a plugin or theme.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Execute ACF options page management operations.
 *
 * @param array $input Input parameters.
 * @return array|WP_Error
 */
function nibwp_acf_manage_options(array $input): array|WP_Error
{
    if (!function_exists('acf_get_field_groups')) {
        return new WP_Error(
            'acf_not_active',
            'Advanced Custom Fields plugin is not active. Please install and activate ACF.',
        );
    }

    $action = (string) $input['action'];
    $page_slug = isset($input['page_slug']) ? (string) $input['page_slug'] : '';
    $page_data = isset($input['page_data']) ? (array) $input['page_data'] : [];
    $values = isset($input['values']) ? (array) $input['values'] : [];

    switch ($action) {
        case 'list_pages':
            if (!function_exists('acf_get_options_pages')) {
                return new WP_Error('acf_pro_required', 'ACF Pro is required for options pages. acf_get_options_pages() is not available.');
            }
            $pages = acf_get_options_pages();
            return ['success' => true, 'data' => array_values($pages ?: []), 'message' => sprintf('Found %d options pages.', count($pages ?: []))];

        case 'create_page':
            if (!function_exists('acf_add_options_page')) {
                return new WP_Error('acf_pro_required', 'ACF Pro is required for options pages. acf_add_options_page() is not available.');
            }
            if (empty($page_data['page_title'])) {
                return new WP_Error('missing_page_title', 'page_data.page_title is required for create_page action.');
            }
            $defaults = [
                'page_title' => '',
                'menu_title' => '',
                'menu_slug' => '',
                'capability' => 'edit_posts',
                'parent_slug' => '',
                'icon_url' => '',
                'redirect' => true,
                'autoload' => false,
            ];
            $args = array_merge($defaults, array_intersect_key($page_data, $defaults));
            if (empty($args['menu_title'])) {
                $args['menu_title'] = $args['page_title'];
            }
            if (empty($args['menu_slug'])) {
                $args['menu_slug'] = sanitize_title($args['page_title']);
            }
            try {
                $page = acf_add_options_page($args);
            } catch (\Throwable $e) {
                return new WP_Error('create_page_failed', sprintf('Failed to create options page: %s', $e->getMessage()));
            }
            return ['success' => true, 'data' => $page, 'message' => 'Options page created for this request. Add registration code to your plugin/theme for persistence.'];

        case 'get_values':
            if (!function_exists('get_fields')) {
                return new WP_Error('acf_not_active', 'ACF get_fields() function is not available.');
            }
            $post_id = $page_slug !== '' ? $page_slug : 'options';
            $field_values = get_fields($post_id);
            if ($field_values === false) {
                $field_values = [];
            }
            return ['success' => true, 'data' => $field_values, 'message' => sprintf('Retrieved %d values from "%s".', count($field_values), $post_id)];

        case 'update_values':
            if (!function_exists('update_field')) {
                return new WP_Error('acf_not_active', 'ACF update_field() function is not available.');
            }
            if (empty($values)) {
                return new WP_Error('missing_values', 'values object is required for update_values action.');
            }
            $post_id = $page_slug !== '' ? $page_slug : 'options';
            $updated = [];
            $errors = [];
            foreach ($values as $field_name => $value) {
                $result = update_field($field_name, $value, $post_id);
                if ($result === false) {
                    $errors[] = $field_name;
                } else {
                    $updated[] = $field_name;
                }
            }
            if (!empty($errors) && empty($updated)) {
                return new WP_Error('update_failed', sprintf('Failed to update fields: %s', implode(', ', $errors)));
            }
            $data = ['updated' => $updated];
            if (!empty($errors)) {
                $data['failed'] = $errors;
            }
            return ['success' => true, 'data' => $data, 'message' => sprintf('Updated %d fields, %d failed.', count($updated), count($errors))];

        default:
            return new WP_Error('invalid_action', sprintf('Unknown action: %s', $action));
    }
}
