<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

// ---------------------------------------------------------------------------
// Ability: nibwp/elementor-create-page
// ---------------------------------------------------------------------------

wp_register_ability('nibwp/elementor-create-page', [
    'label' => __('Elementor – Create / Update Page', domain: 'nibwp'),
    'description' => __(
        'Creates a new WordPress page (or updates an existing one) with structured Elementor section data. '
        . 'The page is saved with Elementor edit-mode enabled so it opens directly in the Elementor editor.',
        domain: 'nibwp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'page_title' => [
                'type' => 'string',
                'description' => 'The page title.',
                'minLength' => 1,
            ],
            'post_id' => [
                'type' => 'integer',
                'description' => 'Existing post ID to update. Omit to create a new page.',
            ],
            'template' => [
                'type' => 'string',
                'description' => 'Page template. Common values: elementor_canvas, elementor_header_footer, default.',
                'default' => 'elementor_canvas',
            ],
            'sections' => [
                'type' => 'array',
                'description' => 'Array of Elementor section objects.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => [
                            'type' => 'string',
                            'description' => 'Element type, e.g. "section".',
                        ],
                        'settings' => [
                            'type' => 'object',
                            'description' => 'Section settings (layout, styling, etc.).',
                        ],
                        'elements' => [
                            'type' => 'array',
                            'description' => 'Child elements (columns, widgets).',
                        ],
                    ],
                ],
            ],
        ],
        'required' => ['page_title'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'description' => 'The WordPress post ID.'],
            'edit_url' => ['type' => 'string', 'description' => 'URL to edit the page in Elementor.'],
            'view_url' => ['type' => 'string', 'description' => 'Front-end URL of the page.'],
        ],
    ],
    'execute_callback' => 'nibwp_elementor_create_page',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Creates or updates an Elementor page with structured section data.',
                '',
                'SECTION STRUCTURE:',
                'Each section follows Elementor\'s nested data model:',
                '  section -> columns -> widgets',
                'Example minimal section:',
                '  {',
                '    "type": "section",',
                '    "settings": { "layout": "boxed" },',
                '    "elements": [{',
                '      "type": "column",',
                '      "settings": { "_column_size": 100 },',
                '      "elements": [{',
                '        "type": "widget",',
                '        "widgetType": "heading",',
                '        "settings": { "title": "Hello World" }',
                '      }]',
                '    }]',
                '  }',
                '',
                'TEMPLATES:',
                '- "elementor_canvas" — blank canvas, no theme header/footer.',
                '- "elementor_header_footer" — includes theme header and footer.',
                '- "default" — standard theme template.',
                '',
                'UPDATING:',
                '- Pass post_id to update an existing page. The title and sections will be replaced.',
                '- Omit post_id to create a brand-new page.',
                '',
                'IMPORTANT:',
                '- Elementor must be active. The ability checks for ELEMENTOR_VERSION.',
                '- Each element must have an "elType" or "type" field. The callback normalises "type" to "elType".',
                '- Widget elements need a "widgetType" field.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Recursively normalise Elementor element data.
 *
 * Ensures every element has `id`, `elType`, and a `settings` array.
 *
 * @param array $element Raw element data.
 * @return array Normalised element.
 */
function nibwp_elementor_normalise_element(array $element): array
{
    if (empty($element['id'])) {
        $element['id'] = bin2hex(random_bytes(4));
    }

    // Allow "type" as an alias for "elType".
    if (!empty($element['type']) && empty($element['elType'])) {
        $element['elType'] = $element['type'];
    }
    unset($element['type']);

    if (!isset($element['settings'])) {
        $element['settings'] = [];
    }

    if (!empty($element['elements']) && is_array($element['elements'])) {
        $element['elements'] = array_map('nibwp_elementor_normalise_element', $element['elements']);
    } else {
        $element['elements'] = [];
    }

    return $element;
}

/**
 * Create or update an Elementor page.
 *
 * @param array $input Input data.
 * @return array|WP_Error
 */
function nibwp_elementor_create_page(array $input): array|WP_Error
{
    if (!defined('ELEMENTOR_VERSION')) {
        return new WP_Error(
            'elementor_not_active',
            __('Elementor is not active on this site.', domain: 'nibwp'),
        );
    }

    $page_title = trim((string) ($input['page_title'] ?? ''));
    if ($page_title === '') {
        return new WP_Error('missing_title', __('page_title is required.', domain: 'nibwp'));
    }

    // Only a default for a page being created. On an update, silence means
    // "leave the template alone" - defaulting to canvas here rewrote the
    // template of every existing page this ability touched.
    $template = isset($input['template']) ? (string) $input['template'] : null;
    $sections = (array) ($input['sections'] ?? []);
    $post_id = isset($input['post_id']) ? (int) $input['post_id'] : 0;

    // Update or create.
    $is_new = $post_id <= 0;
    if ($post_id > 0) {
        $existing = get_post($post_id);
        if (!$existing) {
            return new WP_Error('post_not_found', sprintf(__('Post %d not found.', domain: 'nibwp'), $post_id));
        }
        wp_update_post([
            'ID' => $post_id,
            'post_title' => $page_title,
        ]);
    } else {
        $post_id = wp_insert_post([
            'post_title' => $page_title,
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_content' => '',
        ], true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }
    }

    // Set page template. A new page with nothing said gets canvas, which is what
    // an Elementor page usually wants; an existing one keeps whatever it has.
    if ($template !== null) {
        update_post_meta($post_id, '_wp_page_template', $template);
    } elseif ($is_new) {
        update_post_meta($post_id, '_wp_page_template', 'elementor_canvas');
    }

    // Build Elementor data.
    $elementor_data = array_map('nibwp_elementor_normalise_element', $sections);
    $encoded = wp_json_encode($elementor_data);

    if ($encoded === false) {
        return new WP_Error('json_encode_failed', __('Failed to encode Elementor data as JSON.', domain: 'nibwp'));
    }

    // wp_slash is not optional. update_metadata() runs wp_unslash() over the
    // value, so every backslash JSON needs - the escaped slash, the escaped
    // quote, the unicode escape - is eaten on the way in, and what lands in
    // the database no longer decodes. Elementor then renders nothing while the
    // post still looks built. This is what Elementor's own save does, and what
    // elementor-pro/lib/persister.php has always done.
    update_post_meta($post_id, '_elementor_data', wp_slash($encoded));
    update_post_meta($post_id, '_elementor_edit_mode', 'builder');
    update_post_meta($post_id, '_elementor_version', ELEMENTOR_VERSION);

    return [
        'post_id' => $post_id,
        'edit_url' => admin_url('post.php?post=' . $post_id . '&action=elementor'),
        'view_url' => get_permalink($post_id),
    ];
}

// ---------------------------------------------------------------------------
// Ability: nibwp/elementor-create-widget
// ---------------------------------------------------------------------------

wp_register_ability('nibwp/elementor-create-widget', [
    'label' => __('Elementor – Create Custom Widget', domain: 'nibwp'),
    'description' => __(
        'Generates a custom Elementor widget PHP class and writes it to the NIBWP sandbox directory so it is auto-loaded on every request.',
        domain: 'nibwp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'widget_name' => [
                'type' => 'string',
                'description' => 'Machine name for the widget (lowercase, hyphens). E.g. "pricing-table".',
                'minLength' => 1,
                'pattern' => '^[a-z0-9][a-z0-9-]*$',
            ],
            'widget_title' => [
                'type' => 'string',
                'description' => 'Human-readable title shown in the Elementor panel.',
                'minLength' => 1,
            ],
            'widget_icon' => [
                'type' => 'string',
                'description' => 'Elementor icon class (e.g. "eicon-code").',
                'default' => 'eicon-code',
            ],
            'category' => [
                'type' => 'string',
                'description' => 'Widget category in the Elementor panel.',
                'default' => 'general',
            ],
            'controls' => [
                'type' => 'array',
                'description' => 'Array of control definitions.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string', 'description' => 'Control ID.'],
                        'type' => ['type' => 'string', 'description' => 'Control type constant name, e.g. "TEXT", "TEXTAREA", "SWITCHER", "COLOR".'],
                        'label' => ['type' => 'string', 'description' => 'Control label.'],
                        'default' => ['description' => 'Default value.'],
                        'options' => ['type' => 'object', 'description' => 'Options for SELECT controls.'],
                    ],
                    'required' => ['id', 'type', 'label'],
                ],
            ],
            'render_template' => [
                'type' => 'string',
                'description' => 'PHP code for the render() method body. Use $settings = $this->get_settings_for_display(); to access control values.',
            ],
        ],
        'required' => ['widget_name', 'widget_title'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'file_path' => ['type' => 'string', 'description' => 'Absolute path to the generated widget file.'],
            'widget_class' => ['type' => 'string', 'description' => 'The generated PHP class name.'],
        ],
    ],
    'execute_callback' => 'nibwp_elementor_create_widget',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Generates a custom Elementor Widget_Base subclass and saves it to the sandbox.',
                '',
                'CONTROLS:',
                'Each control needs: id, type, label. Optional: default, options.',
                'Common type values (use the constant name without the Controls_Manager:: prefix):',
                '  TEXT, TEXTAREA, WYSIWYG, NUMBER, SLIDER, COLOR, MEDIA, URL,',
                '  SELECT, SWITCHER, CHOOSE, ICON, FONT, REPEATER, DIMENSIONS.',
                '',
                'RENDER TEMPLATE:',
                'The render_template is placed inside the render() method. Access settings via:',
                '  $settings = $this->get_settings_for_display();',
                '  echo $settings["my_control_id"];',
                '',
                'If render_template is omitted, a placeholder <div> is generated.',
                '',
                'REGISTRATION:',
                'The generated file includes an elementor/widgets/register action hook so the widget',
                'is automatically registered when the sandbox file is loaded.',
                '',
                'NAMING:',
                '- widget_name should be lowercase with hyphens: "pricing-table".',
                '- The PHP class is auto-derived: NIBWP_Widget_Pricing_Table.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Generate and write an Elementor widget class to the sandbox.
 *
 * @param array $input Input data.
 * @return array|WP_Error
 */
function nibwp_elementor_create_widget(array $input): array|WP_Error
{
    if (!defined('ELEMENTOR_VERSION')) {
        return new WP_Error(
            'elementor_not_active',
            __('Elementor is not active on this site.', domain: 'nibwp'),
        );
    }

    $widget_name = trim((string) ($input['widget_name'] ?? ''));
    $widget_title = trim((string) ($input['widget_title'] ?? ''));

    if ($widget_name === '' || $widget_title === '') {
        return new WP_Error('missing_fields', __('widget_name and widget_title are required.', domain: 'nibwp'));
    }

    if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $widget_name)) {
        return new WP_Error('invalid_name', __('widget_name must be lowercase alphanumeric with hyphens.', domain: 'nibwp'));
    }

    $widget_icon = (string) ($input['widget_icon'] ?? 'eicon-code');
    $category = (string) ($input['category'] ?? 'general');
    $controls = (array) ($input['controls'] ?? []);
    $render_template = (string) ($input['render_template'] ?? '');

    // Derive class name: "pricing-table" -> "NIBWP_Widget_Pricing_Table".
    $class_name = 'NIBWP_Widget_' . str_replace(' ', '_', ucwords(str_replace('-', ' ', $widget_name)));

    // Build controls registration code.
    $controls_code = '';
    foreach ($controls as $control) {
        if (empty($control['id']) || empty($control['type']) || empty($control['label'])) {
            continue;
        }
        $ctrl_id = var_export((string) $control['id'], true);
        $ctrl_type = strtoupper((string) $control['type']);
        $ctrl_label = var_export((string) $control['label'], true);
        $ctrl_args = "[\n                'label' => {$ctrl_label},\n";

        if (isset($control['default'])) {
            $ctrl_default = var_export($control['default'], true);
            $ctrl_args .= "                'default' => {$ctrl_default},\n";
        }
        if (!empty($control['options']) && is_array($control['options'])) {
            $ctrl_options = var_export($control['options'], true);
            $ctrl_args .= "                'options' => {$ctrl_options},\n";
        }
        $ctrl_args .= '            ]';
        $controls_code .= <<<PHP

            \$this->add_control(
                {$ctrl_id},
                array_merge({$ctrl_args}, ['type' => \\Elementor\\Controls_Manager::{$ctrl_type}])
            );

        PHP;
    }

    // Render body.
    if ($render_template === '') {
        $render_template = '        $settings = $this->get_settings_for_display();' . "\n"
            . '        echo \'<div class="nibwp-widget-' . esc_attr($widget_name) . '">Widget output here</div>\';';
    } else {
        // Indent the user-provided code.
        $render_template = '        ' . str_replace("\n", "\n        ", $render_template);
    }

    $safe_title = addslashes($widget_title);
    $safe_icon = addslashes($widget_icon);
    $safe_category = addslashes($category);

    $php = <<<PHP
<?php
declare(strict_types=1);

if (!defined('ABSPATH')) { exit(); }

/**
 * Custom Elementor widget: {$widget_title}
 * Generated by NIBWP.
 */

class {$class_name} extends \\Elementor\\Widget_Base {

    public function get_name(): string {
        return '{$widget_name}';
    }

    public function get_title(): string {
        return '{$safe_title}';
    }

    public function get_icon(): string {
        return '{$safe_icon}';
    }

    public function get_categories(): array {
        return ['{$safe_category}'];
    }

    protected function register_controls(): void {
        \$this->start_controls_section(
            'content_section',
            [
                'label' => '{$safe_title}',
                'tab' => \\Elementor\\Controls_Manager::TAB_CONTENT,
            ]
        );
{$controls_code}
        \$this->end_controls_section();
    }

    protected function render(): void {
{$render_template}
    }
}

add_action('elementor/widgets/register', function (\$widgets_manager) {
    \$widgets_manager->register(new {$class_name}());
});

PHP;

    // Write to sandbox.
    $sandbox_dir = WP_CONTENT_DIR . '/nibwp-sandbox';
    if (!is_dir($sandbox_dir)) {
        wp_mkdir_p($sandbox_dir);
    }

    $file_path = $sandbox_dir . '/elementor-widget-' . $widget_name . '.php';
    $written = file_put_contents($file_path, $php, LOCK_EX);

    if ($written === false) {
        return new WP_Error('write_failed', sprintf(__('Failed to write widget file: %s', domain: 'nibwp'), $file_path));
    }

    return [
        'file_path' => $file_path,
        'widget_class' => $class_name,
    ];
}

// ---------------------------------------------------------------------------
// Ability: nibwp/elementor-global-styles
// ---------------------------------------------------------------------------

wp_register_ability('nibwp/elementor-global-styles', [
    'label' => __('Elementor – Global Styles', domain: 'nibwp'),
    'description' => __(
        'Manages Elementor global colors and fonts via the Kit system. Supports getting, setting, and adding global style items.',
        domain: 'nibwp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'description' => 'The operation to perform.',
                'enum' => ['get', 'set', 'add'],
            ],
            'type' => [
                'type' => 'string',
                'description' => 'Whether to manage colors or fonts.',
                'enum' => ['colors', 'fonts'],
            ],
            'items' => [
                'type' => 'array',
                'description' => 'Items to set or add. Required for "set" and "add" actions.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string', 'description' => 'Unique item ID. Auto-generated for "add" if omitted.'],
                        'title' => ['type' => 'string', 'description' => 'Human-readable name.'],
                        'color' => ['type' => 'string', 'description' => 'CSS color value (for colors type).'],
                        'family' => ['type' => 'string', 'description' => 'Font family name (for fonts type).'],
                        'weight' => ['type' => 'string', 'description' => 'Font weight (for fonts type).'],
                        'style' => ['type' => 'string', 'description' => 'Font style, e.g. "normal", "italic" (for fonts type).'],
                    ],
                ],
            ],
        ],
        'required' => ['action', 'type'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'items' => [
                'type' => 'array',
                'description' => 'The resulting global style items after the operation.',
            ],
        ],
    ],
    'execute_callback' => 'nibwp_elementor_global_styles',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manages Elementor global colors and global fonts through the Kit system.',
                '',
                'ACTIONS:',
                '- "get" — Retrieve all current global colors or fonts. No items needed.',
                '- "set" — Replace ALL global colors or fonts with the provided items array.',
                '- "add" — Append new items to the existing global colors or fonts.',
                '',
                'COLOR ITEMS:',
                '  { "id": "primary", "title": "Primary", "color": "#3B82F6" }',
                '  The "id" field must be unique. If omitted on "add", one is auto-generated.',
                '',
                'FONT ITEMS:',
                '  { "id": "heading", "title": "Heading Font", "family": "Inter", "weight": "700" }',
                '',
                'IMPORTANT:',
                '- The "set" action is destructive: it replaces the entire list.',
                '- Use "get" first to inspect existing items before modifying.',
                '- Changes apply site-wide via the Elementor Kit.',
                '- Elementor must be active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Manage Elementor global colors and fonts.
 *
 * @param array $input Input data.
 * @return array|WP_Error
 */
function nibwp_elementor_global_styles(array $input): array|WP_Error
{
    if (!defined('ELEMENTOR_VERSION')) {
        return new WP_Error(
            'elementor_not_active',
            __('Elementor is not active on this site.', domain: 'nibwp'),
        );
    }

    $action = (string) ($input['action'] ?? '');
    $type = (string) ($input['type'] ?? '');

    if (!in_array($action, ['get', 'set', 'add'], true)) {
        return new WP_Error('invalid_action', __('action must be one of: get, set, add.', domain: 'nibwp'));
    }
    if (!in_array($type, ['colors', 'fonts'], true)) {
        return new WP_Error('invalid_type', __('type must be one of: colors, fonts.', domain: 'nibwp'));
    }

    if (in_array($action, ['set', 'add'], true) && empty($input['items'])) {
        return new WP_Error('missing_items', __('items array is required for set/add actions.', domain: 'nibwp'));
    }

    $kit_id = (int) get_option('elementor_active_kit');
    if ($kit_id <= 0) {
        return new WP_Error('no_kit', __('No active Elementor Kit found.', domain: 'nibwp'));
    }

    $page_settings = (array) get_post_meta($kit_id, '_elementor_page_settings', true);
    $settings_key = $type === 'colors' ? 'system_colors' : 'system_typography';
    $custom_key = $type === 'colors' ? 'custom_colors' : 'custom_typography';

    // Elementor stores system items and custom items separately.
    // We expose them merged for simplicity; writes go to the custom key.
    $system_items = (array) ($page_settings[$settings_key] ?? []);
    $custom_items = (array) ($page_settings[$custom_key] ?? []);
    $all_items = array_merge($system_items, $custom_items);

    if ($action === 'get') {
        return ['items' => array_values($all_items)];
    }

    $new_items = (array) ($input['items'] ?? []);

    // Ensure every item has an ID.
    foreach ($new_items as &$item) {
        if (empty($item['id'])) {
            $item['_id'] = bin2hex(random_bytes(4));
        } else {
            $item['_id'] = (string) $item['id'];
        }
    }
    unset($item);

    if ($action === 'set') {
        $page_settings[$custom_key] = array_values($new_items);
    } elseif ($action === 'add') {
        $page_settings[$custom_key] = array_values(array_merge($custom_items, $new_items));
    }

    update_post_meta($kit_id, '_elementor_page_settings', $page_settings);

    // Re-read to return the current state.
    $page_settings = (array) get_post_meta($kit_id, '_elementor_page_settings', true);
    $result_items = array_merge(
        (array) ($page_settings[$settings_key] ?? []),
        (array) ($page_settings[$custom_key] ?? []),
    );

    return ['items' => array_values($result_items)];
}

// ---------------------------------------------------------------------------
// Ability: nibwp/elementor-manage-templates
// ---------------------------------------------------------------------------

wp_register_ability('nibwp/elementor-manage-templates', [
    'label' => __('Elementor – Manage Templates', domain: 'nibwp'),
    'description' => __(
        'List, create, or retrieve Elementor templates from the template library (elementor_library post type).',
        domain: 'nibwp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'description' => 'The operation to perform.',
                'enum' => ['list', 'create', 'get'],
            ],
            'template_type' => [
                'type' => 'string',
                'description' => 'Template type for creation. E.g. "page", "section", "header", "footer", "single", "archive", "popup".',
            ],
            'title' => [
                'type' => 'string',
                'description' => 'Template title (required for create).',
            ],
            'content' => [
                'type' => 'array',
                'description' => 'Elementor data array (required for create). Same structure as sections in elementor-create-page.',
            ],
            'template_id' => [
                'type' => 'integer',
                'description' => 'Template post ID (required for get).',
            ],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'templates' => [
                'type' => 'array',
                'description' => 'Array of template summaries (for list action).',
            ],
            'template' => [
                'type' => 'object',
                'description' => 'Single template data (for get/create actions).',
            ],
        ],
    ],
    'execute_callback' => 'nibwp_elementor_manage_templates',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage Elementor templates in the template library.',
                '',
                'ACTIONS:',
                '- "list" — Returns all templates with id, title, type, date, and edit_url.',
                '- "create" — Creates a new template. Requires title and template_type.',
                '  Optionally pass content (Elementor data array) to populate it.',
                '- "get" — Retrieves a single template by template_id, including its Elementor data.',
                '',
                'TEMPLATE TYPES:',
                '  "page", "section", "header", "footer", "single", "archive", "popup".',
                '',
                'CONTENT FORMAT:',
                'Same nested element structure as nibwp/elementor-create-page sections.',
                'Elements are normalised automatically (IDs generated, elType mapped).',
                '',
                'IMPORTANT:',
                '- Elementor must be active.',
                '- Templates are stored as the "elementor_library" post type.',
                '- Created templates start in "draft" status.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Manage Elementor templates.
 *
 * @param array $input Input data.
 * @return array|WP_Error
 */
function nibwp_elementor_manage_templates(array $input): array|WP_Error
{
    if (!defined('ELEMENTOR_VERSION')) {
        return new WP_Error(
            'elementor_not_active',
            __('Elementor is not active on this site.', domain: 'nibwp'),
        );
    }

    $action = (string) ($input['action'] ?? '');

    if (!in_array($action, ['list', 'create', 'get'], true)) {
        return new WP_Error('invalid_action', __('action must be one of: list, create, get.', domain: 'nibwp'));
    }

    // ---- LIST ----
    if ($action === 'list') {
        $query = new WP_Query([
            'post_type' => 'elementor_library',
            'post_status' => 'any',
            'posts_per_page' => 100,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $templates = [];
        foreach ($query->posts as $post) {
            $templates[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'type' => get_post_meta($post->ID, '_elementor_template_type', true) ?: 'unknown',
                'date' => $post->post_date,
                'status' => $post->post_status,
                'edit_url' => admin_url('post.php?post=' . $post->ID . '&action=elementor'),
            ];
        }

        return ['templates' => $templates];
    }

    // ---- GET ----
    if ($action === 'get') {
        $template_id = (int) ($input['template_id'] ?? 0);
        if ($template_id <= 0) {
            return new WP_Error('missing_template_id', __('template_id is required for the get action.', domain: 'nibwp'));
        }

        $post = get_post($template_id);
        if (!$post || $post->post_type !== 'elementor_library') {
            return new WP_Error('not_found', sprintf(__('Elementor template %d not found.', domain: 'nibwp'), $template_id));
        }

        $raw_data = get_post_meta($template_id, '_elementor_data', true);
        $elementor_data = is_string($raw_data) ? json_decode($raw_data, true) : (is_array($raw_data) ? $raw_data : []);

        return [
            'template' => [
                'id' => $post->ID,
                'title' => $post->post_title,
                'type' => get_post_meta($post->ID, '_elementor_template_type', true) ?: 'unknown',
                'date' => $post->post_date,
                'status' => $post->post_status,
                'content' => $elementor_data,
                'edit_url' => admin_url('post.php?post=' . $post->ID . '&action=elementor'),
            ],
        ];
    }

    // ---- CREATE ----
    $title = trim((string) ($input['title'] ?? ''));
    $template_type = trim((string) ($input['template_type'] ?? 'page'));

    if ($title === '') {
        return new WP_Error('missing_title', __('title is required for the create action.', domain: 'nibwp'));
    }

    $post_id = wp_insert_post([
        'post_title' => $title,
        'post_type' => 'elementor_library',
        'post_status' => 'draft',
        'post_content' => '',
    ], true);

    if (is_wp_error($post_id)) {
        return $post_id;
    }

    update_post_meta($post_id, '_elementor_template_type', $template_type);
    update_post_meta($post_id, '_elementor_edit_mode', 'builder');
    update_post_meta($post_id, '_elementor_version', ELEMENTOR_VERSION);

    // Set taxonomy term for template type.
    wp_set_object_terms($post_id, $template_type, 'elementor_library_type');

    // Save content if provided.
    $content = (array) ($input['content'] ?? []);
    if (!empty($content)) {
        $normalised = array_map('nibwp_elementor_normalise_element', $content);
        $encoded = wp_json_encode($normalised);
        if ($encoded !== false) {
            // Same reason as elementor-create-page: unslashed JSON does not
            // survive update_metadata(), and the template renders blank.
            update_post_meta($post_id, '_elementor_data', wp_slash($encoded));
        }
    }

    return [
        'template' => [
            'id' => $post_id,
            'title' => $title,
            'type' => $template_type,
            'edit_url' => admin_url('post.php?post=' . $post_id . '&action=elementor'),
        ],
    ];
}

// ---------------------------------------------------------------------------
// Ability: nibwp/elementor-list-pages
// ---------------------------------------------------------------------------

wp_register_ability('nibwp/elementor-list-pages', [
    'label' => __('Elementor – List Pages / Posts', domain: 'nibwp'),
    'description' => __(
        'List every post or page that was built with Elementor (presence of `_elementor_data` meta). Supports filters by post_type, status, search, and pagination.',
        domain: 'nibwp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_type' => [
                'type' => 'string',
                'description' => 'Post type to scan. Default any.',
                'default' => 'any',
            ],
            'status'    => ['type' => 'string', 'default' => 'publish', 'description' => 'publish/draft/pending/any.'],
            'search'    => ['type' => 'string'],
            'per_page'  => ['type' => 'integer', 'default' => 25],
            'page'      => ['type' => 'integer', 'default' => 1],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'pages' => ['type' => 'array'],
            'total' => ['type' => 'integer'],
            'pages_count' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => 'nibwp_elementor_list_pages',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'List Elementor-built content. Use this to discover what pages exist before reading/updating them with elementor-get-page-structure / elementor-create-page.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_elementor_list_pages(array $input): array|WP_Error
{
    if (!defined('ELEMENTOR_VERSION')) {
        return new WP_Error('elementor_not_active', 'Elementor is not active.');
    }

    $per_page = min(max((int) ($input['per_page'] ?? 25), 1), 100);
    $page = max((int) ($input['page'] ?? 1), 1);

    $args = [
        'post_type'      => (string) ($input['post_type'] ?? 'any'),
        'post_status'    => (string) ($input['status'] ?? 'publish'),
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'meta_query'     => [[
            'key'     => '_elementor_data',
            'compare' => 'EXISTS',
        ]],
    ];
    if (!empty($input['search'])) {
        $args['s'] = (string) $input['search'];
    }

    $q = new WP_Query($args);

    $pages = [];
    foreach ($q->posts as $p) {
        $pages[] = [
            'id'          => $p->ID,
            'title'       => $p->post_title,
            'post_type'   => $p->post_type,
            'status'      => $p->post_status,
            'modified'    => $p->post_modified_gmt,
            'edit_url'    => admin_url('post.php?post=' . $p->ID . '&action=elementor'),
            'permalink'   => get_permalink($p),
            'edit_mode'   => (string) get_post_meta($p->ID, '_elementor_edit_mode', true),
            'template'    => (string) get_post_meta($p->ID, '_wp_page_template', true),
        ];
    }

    return [
        'pages'       => $pages,
        'total'       => (int) $q->found_posts,
        'pages_count' => (int) $q->max_num_pages,
    ];
}

// ---------------------------------------------------------------------------
// Ability: nibwp/elementor-get-page-structure
// ---------------------------------------------------------------------------

wp_register_ability('nibwp/elementor-get-page-structure', [
    'label' => __('Elementor – Get Page Structure', domain: 'nibwp'),
    'description' => __(
        'Read the raw Elementor data tree for an existing post (sections / columns / widgets) plus the page template and edit mode. Use this to inspect a page before mutating it.',
        domain: 'nibwp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'as'      => [
                'type' => 'string',
                'enum' => ['tree', 'summary'],
                'default' => 'tree',
                'description' => 'tree = full Elementor data; summary = compact widget tally per section.',
            ],
        ],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'title'   => ['type' => 'string'],
            'template' => ['type' => 'string'],
            'edit_mode' => ['type' => 'string'],
            'data'    => ['type' => ['array', 'object']],
            'summary' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => 'nibwp_elementor_get_page_structure',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Inspect an Elementor page. Returns parsed _elementor_data JSON tree (or a compact summary). Pair with elementor-create-page (passing post_id) to modify the same page.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_elementor_get_page_structure(array $input): array|WP_Error
{
    if (!defined('ELEMENTOR_VERSION')) {
        return new WP_Error('elementor_not_active', 'Elementor is not active.');
    }

    $post_id = (int) ($input['post_id'] ?? 0);
    $post = get_post($post_id);
    if (!$post) {
        return new WP_Error('post_not_found', sprintf('Post %d not found.', $post_id));
    }

    $raw = get_post_meta($post_id, '_elementor_data', true);
    $data = is_string($raw) ? json_decode($raw, true) : [];
    if (!is_array($data)) {
        $data = [];
    }

    $out = [
        'post_id'   => $post_id,
        'title'     => $post->post_title,
        'template'  => (string) get_post_meta($post_id, '_wp_page_template', true),
        'edit_mode' => (string) get_post_meta($post_id, '_elementor_edit_mode', true),
    ];

    if (($input['as'] ?? 'tree') === 'summary') {
        $widget_tally = [];
        $section_count = 0;
        $walk = static function (array $nodes) use (&$walk, &$widget_tally, &$section_count): void {
            foreach ($nodes as $node) {
                $el = $node['elType'] ?? '';
                if ($el === 'section' || $el === 'container') {
                    $section_count++;
                } elseif ($el === 'widget') {
                    $type = (string) ($node['widgetType'] ?? 'unknown');
                    $widget_tally[$type] = ($widget_tally[$type] ?? 0) + 1;
                }
                if (!empty($node['elements']) && is_array($node['elements'])) {
                    $walk($node['elements']);
                }
            }
        };
        $walk($data);
        $out['summary'] = [
            'sections' => $section_count,
            'widgets'  => $widget_tally,
            'total_nodes' => count($data),
        ];
    } else {
        $out['data'] = $data;
    }

    return $out;
}
