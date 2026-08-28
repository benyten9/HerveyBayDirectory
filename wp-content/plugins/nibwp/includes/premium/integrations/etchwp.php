<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

// ── EtchWP: Manage Blocks ──

wp_register_ability('nibwp/etchwp-manage-blocks', [
    'label' => __('EtchWP — Manage Blocks', domain: 'nibwp'),
    'description' => __(
        'List, create, update, and delete custom blocks registered by EtchWP. Supports block metadata, render callbacks, and editor settings.',
        domain: 'nibwp',
    ),
    'category' => 'etchwp',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list', 'get', 'create', 'update', 'delete'],
                'description' => 'Action to perform.',
            ],
            'block_name' => [
                'type' => 'string',
                'description' => 'Block name (namespace/name format) for get/update/delete.',
            ],
            'block_data' => [
                'type' => 'object',
                'description' => 'Block configuration for create/update: title, description, category, icon, attributes, render_callback code.',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'category' => ['type' => 'string'],
                    'icon' => ['type' => 'string'],
                    'keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'attributes' => ['type' => 'object'],
                    'render_template' => ['type' => 'string', 'description' => 'PHP render template code.'],
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
            'data' => ['description' => 'Result data.'],
            'message' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => 'nibwp_etchwp_manage_blocks',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage custom blocks registered through EtchWP.',
                'Use "list" to see all registered blocks.',
                'Use "create" with block_data to register a new block.',
                'Block names should use namespace/name format (e.g. "etch/hero-section").',
                'The render_template is PHP code that outputs the block HTML.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_etchwp_manage_blocks(array $input): array|WP_Error
{
    if (!defined('ETCH_PLUGIN_FILE')) {
        return new WP_Error('etchwp_not_active', 'EtchWP plugin is not active.');
    }

    $action = (string) ($input['action'] ?? '');

    try {
        return match ($action) {
            'list' => nibwp_etchwp_list_blocks(),
            'get' => nibwp_etchwp_get_block($input),
            'create' => nibwp_etchwp_create_block($input),
            'update' => nibwp_etchwp_update_block($input),
            'delete' => nibwp_etchwp_delete_block($input),
            default => new WP_Error('invalid_action', "Unknown action: $action"),
        };
    } catch (\Throwable $e) {
        return new WP_Error('etchwp_error', $e->getMessage());
    }
}

function nibwp_etchwp_list_blocks(): array
{
    $registry = WP_Block_Type_Registry::get_instance();
    $blocks = [];
    foreach ($registry->get_all_registered() as $block) {
        $blocks[] = [
            'name' => $block->name,
            'title' => $block->title,
            'description' => $block->description,
            'category' => $block->category,
            'icon' => is_string($block->icon) ? $block->icon : 'block-default',
            'keywords' => $block->keywords,
        ];
    }
    return ['success' => true, 'data' => $blocks, 'message' => count($blocks) . ' blocks found.'];
}

function nibwp_etchwp_get_block(array $input): array|WP_Error
{
    $name = (string) ($input['block_name'] ?? '');
    if ($name === '') {
        return new WP_Error('missing_name', 'block_name is required.');
    }
    $registry = WP_Block_Type_Registry::get_instance();
    $block = $registry->get_registered($name);
    if ($block === null) {
        return new WP_Error('not_found', "Block '$name' is not registered.");
    }
    return [
        'success' => true,
        'data' => [
            'name' => $block->name,
            'title' => $block->title,
            'description' => $block->description,
            'category' => $block->category,
            'icon' => is_string($block->icon) ? $block->icon : 'block-default',
            'keywords' => $block->keywords,
            'attributes' => $block->attributes,
            'supports' => $block->supports,
        ],
    ];
}

function nibwp_etchwp_create_block(array $input): array|WP_Error
{
    $data = $input['block_data'] ?? [];
    $name = (string) ($input['block_name'] ?? '');
    if ($name === '' || !is_array($data)) {
        return new WP_Error('missing_data', 'block_name and block_data are required.');
    }

    $args = [
        'title' => (string) ($data['title'] ?? $name),
        'description' => (string) ($data['description'] ?? ''),
        'category' => (string) ($data['category'] ?? 'common'),
        'icon' => (string) ($data['icon'] ?? 'block-default'),
        'keywords' => $data['keywords'] ?? [],
        'attributes' => $data['attributes'] ?? [],
    ];

    if (!empty($data['render_template'])) {
        $render_code = (string) $data['render_template'];
        $args['render_callback'] = static function ($attributes, $content) use ($render_code) {
            ob_start();
            // @mago-ignore lint:no-eval
            eval($render_code);
            return ob_get_clean();
        };
    }

    $result = register_block_type($name, $args);
    if ($result === false) {
        return new WP_Error('register_failed', "Failed to register block '$name'.");
    }

    return ['success' => true, 'data' => ['name' => $name], 'message' => "Block '$name' registered."];
}

function nibwp_etchwp_update_block(array $input): array|WP_Error
{
    $name = (string) ($input['block_name'] ?? '');
    if ($name === '') {
        return new WP_Error('missing_name', 'block_name is required.');
    }
    $registry = WP_Block_Type_Registry::get_instance();
    if ($registry->get_registered($name) !== null) {
        $registry->unregister($name);
    }
    return nibwp_etchwp_create_block($input);
}

function nibwp_etchwp_delete_block(array $input): array|WP_Error
{
    $name = (string) ($input['block_name'] ?? '');
    if ($name === '') {
        return new WP_Error('missing_name', 'block_name is required.');
    }
    $registry = WP_Block_Type_Registry::get_instance();
    if ($registry->get_registered($name) === null) {
        return new WP_Error('not_found', "Block '$name' is not registered.");
    }
    $registry->unregister($name);
    return ['success' => true, 'message' => "Block '$name' unregistered."];
}

// ── EtchWP: Manage Custom Fields ──

wp_register_ability('nibwp/etchwp-manage-fields', [
    'label' => __('EtchWP — Manage Custom Fields', domain: 'nibwp'),
    'description' => __(
        'List, create, and manage custom fields registered through EtchWP. Read and write field values on posts, users, and terms.',
        domain: 'nibwp',
    ),
    'category' => 'etchwp',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list_groups', 'get_values', 'update_values'],
                'description' => 'Action to perform.',
            ],
            'object_type' => ['type' => 'string', 'enum' => ['post', 'user', 'term'], 'description' => 'Object type for get/update values.'],
            'object_id' => ['type' => 'integer', 'description' => 'Object ID for get/update values.'],
            'fields' => ['type' => 'object', 'description' => 'Key-value pairs for update_values.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'data' => ['description' => 'Result data.'],
        ],
    ],
    'execute_callback' => 'nibwp_etchwp_manage_fields',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Manage EtchWP custom fields. Use list_groups to see registered field groups. Use get_values/update_values with object_type and object_id to read/write meta.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_etchwp_manage_fields(array $input): array|WP_Error
{
    if (!defined('ETCH_PLUGIN_FILE')) {
        return new WP_Error('etchwp_not_active', 'EtchWP plugin is not active.');
    }

    $action = (string) ($input['action'] ?? '');

    return match ($action) {
        'list_groups' => [
            'success' => true,
            'data' => get_option('etch_custom_fields', []),
            'message' => 'Custom field groups retrieved.',
        ],
        'get_values' => nibwp_etchwp_get_field_values($input),
        'update_values' => nibwp_etchwp_update_field_values($input),
        default => new WP_Error('invalid_action', "Unknown action: $action"),
    };
}

function nibwp_etchwp_get_field_values(array $input): array|WP_Error
{
    $type = (string) ($input['object_type'] ?? 'post');
    $id = (int) ($input['object_id'] ?? 0);
    if ($id <= 0) {
        return new WP_Error('missing_id', 'object_id is required.');
    }

    $meta = match ($type) {
        'post' => get_post_meta($id),
        'user' => get_user_meta($id),
        'term' => get_term_meta($id),
        default => new WP_Error('invalid_type', "Unknown object_type: $type"),
    };

    if (is_wp_error($meta)) {
        return $meta;
    }

    // Flatten single-value meta.
    $flat = [];
    foreach ($meta as $key => $values) {
        $flat[$key] = count($values) === 1 ? $values[0] : $values;
    }

    return ['success' => true, 'data' => $flat];
}

function nibwp_etchwp_update_field_values(array $input): array|WP_Error
{
    $type = (string) ($input['object_type'] ?? 'post');
    $id = (int) ($input['object_id'] ?? 0);
    $fields = $input['fields'] ?? [];

    if ($id <= 0 || !is_array($fields) || $fields === []) {
        return new WP_Error('missing_data', 'object_id and fields are required.');
    }

    $updated = [];
    foreach ($fields as $key => $value) {
        $key = sanitize_key($key);
        $result = match ($type) {
            'post' => update_post_meta($id, $key, $value),
            'user' => update_user_meta($id, $key, $value),
            'term' => update_term_meta($id, $key, $value),
            default => false,
        };
        if ($result !== false) {
            $updated[] = $key;
        }
    }

    return ['success' => true, 'data' => ['updated_fields' => $updated], 'message' => count($updated) . ' field(s) updated.'];
}

// ── EtchWP: Manage Styles ──

wp_register_ability('nibwp/etchwp-manage-styles', [
    'label' => __('EtchWP — Manage Styles', domain: 'nibwp'),
    'description' => __(
        'Manage global styles, CSS custom properties, and theme.json settings via EtchWP. Read and update design tokens.',
        domain: 'nibwp',
    ),
    'category' => 'etchwp',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['get_theme_json', 'update_theme_json', 'get_global_styles', 'update_global_styles'],
                'description' => 'Action to perform.',
            ],
            'path' => ['type' => 'string', 'description' => 'Dot-notation path within theme.json (e.g. "settings.color.palette").'],
            'value' => ['description' => 'Value to set at the given path.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'data' => ['description' => 'Result data.'],
        ],
    ],
    'execute_callback' => 'nibwp_etchwp_manage_styles',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage styles and design tokens via EtchWP.',
                'get_theme_json: Returns the full or partial theme.json. Use "path" to get a specific section.',
                'update_theme_json: Update a specific path in theme.json with a new value.',
                'get_global_styles: Returns the active global styles post.',
                'update_global_styles: Updates the global styles post.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_etchwp_manage_styles(array $input): array|WP_Error
{
    if (!defined('ETCH_PLUGIN_FILE')) {
        return new WP_Error('etchwp_not_active', 'EtchWP plugin is not active.');
    }

    $action = (string) ($input['action'] ?? '');

    return match ($action) {
        'get_theme_json' => nibwp_etchwp_get_theme_json($input),
        'update_theme_json' => nibwp_etchwp_update_theme_json($input),
        'get_global_styles' => nibwp_etchwp_get_global_styles(),
        'update_global_styles' => nibwp_etchwp_update_global_styles($input),
        default => new WP_Error('invalid_action', "Unknown action: $action"),
    };
}

function nibwp_etchwp_get_theme_json(array $input): array
{
    $resolver = WP_Theme_JSON_Resolver::get_merged_data();
    $data = $resolver->get_raw_data();

    $path = (string) ($input['path'] ?? '');
    if ($path !== '') {
        $keys = explode('.', $path);
        $current = $data;
        foreach ($keys as $key) {
            if (is_array($current) && array_key_exists($key, $current)) {
                $current = $current[$key];
            } else {
                return ['success' => true, 'data' => null, 'message' => "Path '$path' not found."];
            }
        }
        return ['success' => true, 'data' => $current];
    }

    return ['success' => true, 'data' => $data];
}

function nibwp_etchwp_update_theme_json(array $input): array|WP_Error
{
    $path = (string) ($input['path'] ?? '');
    if ($path === '' || !array_key_exists('value', $input)) {
        return new WP_Error('missing_data', 'path and value are required.');
    }

    // Read current theme.json file.
    $theme_dir = get_stylesheet_directory();
    $json_path = $theme_dir . '/theme.json';

    if (!file_exists($json_path)) {
        return new WP_Error('no_theme_json', 'No theme.json found in the active theme.');
    }

    $content = file_get_contents($json_path);
    $data = json_decode($content, true);
    if (!is_array($data)) {
        return new WP_Error('parse_error', 'Could not parse theme.json.');
    }

    // Set value at path.
    $keys = explode('.', $path);
    $ref = &$data;
    foreach ($keys as $key) {
        if (!isset($ref[$key]) || !is_array($ref[$key])) {
            $ref[$key] = [];
        }
        $ref = &$ref[$key];
    }
    $ref = $input['value'];

    $encoded = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return new WP_Error('encode_error', 'Failed to encode theme.json.');
    }

    file_put_contents($json_path, $encoded);

    return ['success' => true, 'message' => "Updated theme.json at '$path'."];
}

function nibwp_etchwp_get_global_styles(): array
{
    $global_styles_id = wp_get_global_styles_id();
    if ($global_styles_id === 0) {
        return ['success' => true, 'data' => null, 'message' => 'No global styles post found.'];
    }

    $post = get_post($global_styles_id);
    if ($post === null) {
        return ['success' => true, 'data' => null, 'message' => 'Global styles post not found.'];
    }

    $content = json_decode($post->post_content, true);
    return ['success' => true, 'data' => $content ?? []];
}

function nibwp_etchwp_update_global_styles(array $input): array|WP_Error
{
    if (!array_key_exists('value', $input)) {
        return new WP_Error('missing_data', 'value is required (the global styles object).');
    }

    $global_styles_id = wp_get_global_styles_id();
    if ($global_styles_id === 0) {
        return new WP_Error('no_global_styles', 'No global styles post found.');
    }

    $encoded = wp_json_encode($input['value'], JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return new WP_Error('encode_error', 'Failed to encode styles.');
    }

    $result = wp_update_post([
        'ID' => $global_styles_id,
        'post_content' => $encoded,
    ]);

    if (is_wp_error($result)) {
        return $result;
    }

    return ['success' => true, 'message' => 'Global styles updated.'];
}

// ── EtchWP: Manage Assets ──

wp_register_ability('nibwp/etchwp-manage-assets', [
    'label' => __('EtchWP — Manage Assets', domain: 'nibwp'),
    'description' => __(
        'List, enqueue, and manage CSS/JS assets registered by EtchWP. View registered scripts and styles, check dependencies.',
        domain: 'nibwp',
    ),
    'category' => 'etchwp',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list_styles', 'list_scripts', 'get_asset'],
                'description' => 'Action to perform.',
            ],
            'handle' => ['type' => 'string', 'description' => 'Asset handle for get_asset.'],
            'type' => ['type' => 'string', 'enum' => ['style', 'script'], 'description' => 'Asset type for get_asset.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'data' => ['description' => 'Result data.'],
        ],
    ],
    'execute_callback' => 'nibwp_etchwp_manage_assets',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'List and inspect registered CSS/JS assets. Use list_styles or list_scripts to see all registered assets. Use get_asset with a handle to see details.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_etchwp_manage_assets(array $input): array|WP_Error
{
    if (!defined('ETCH_PLUGIN_FILE')) {
        return new WP_Error('etchwp_not_active', 'EtchWP plugin is not active.');
    }

    $action = (string) ($input['action'] ?? '');

    return match ($action) {
        'list_styles' => nibwp_etchwp_list_assets('style'),
        'list_scripts' => nibwp_etchwp_list_assets('script'),
        'get_asset' => nibwp_etchwp_get_asset($input),
        default => new WP_Error('invalid_action', "Unknown action: $action"),
    };
}

function nibwp_etchwp_list_assets(string $type): array
{
    /** @var WP_Scripts|WP_Styles $registry */
    $registry = $type === 'script' ? wp_scripts() : wp_styles();
    $items = [];
    foreach ($registry->registered as $handle => $dep) {
        $items[] = [
            'handle' => $handle,
            'src' => $dep->src,
            'version' => $dep->ver,
            'deps' => $dep->deps,
        ];
    }
    return ['success' => true, 'data' => $items, 'message' => count($items) . " {$type}s registered."];
}

function nibwp_etchwp_get_asset(array $input): array|WP_Error
{
    $handle = (string) ($input['handle'] ?? '');
    $type = (string) ($input['type'] ?? 'script');

    if ($handle === '') {
        return new WP_Error('missing_handle', 'handle is required.');
    }

    $registry = $type === 'script' ? wp_scripts() : wp_styles();
    $dep = $registry->registered[$handle] ?? null;

    if ($dep === null) {
        return new WP_Error('not_found', "Asset '$handle' not found.");
    }

    return [
        'success' => true,
        'data' => [
            'handle' => $handle,
            'type' => $type,
            'src' => $dep->src,
            'version' => $dep->ver,
            'deps' => $dep->deps,
            'extra' => $dep->extra,
        ],
    ];
}

// =============================================================================
// Media: replacing an attachment's file through Etch's own endpoint
// =============================================================================

/**
 * Etch ships POST /wp-json/etch-api/replace/{id}, which replaces an
 * attachment's file, clears its old thumbnails and regenerates them.
 *
 * It cannot be dispatched in-process. The handler runs wp_handle_upload(), and
 * core then tests the temp file with is_uploaded_file() — a test only a real
 * HTTP upload passes. So this posts back to the site over loopback, signed with
 * a session minted here. No application password, nothing for anyone to hand
 * around in a chat window.
 *
 * Off by default, because nibwp_wp_replace_media() already does this work
 * in-process without the round trip. It is wired up so that when Etch's media
 * handling grows past what core gives us, turning it on is a filter:
 *
 *     add_filter('nibwp_replace_media_use_etch', '__return_true');
 */
add_filter('nibwp_replace_media', 'nibwp_etchwp_replace_media', 10, 3);

function nibwp_etchwp_replace_media(?array $result, int $id, array $input): ?array
{
    if ($result !== null) {
        return $result;
    }

    // Etch has to actually be running. Same guard every other ability in this
    // file uses, and it comes before anything else so a site without Etch never
    // reaches the opt-in filter or pays for a route scan.
    if (!defined('ETCH_PLUGIN_FILE')) {
        return null;
    }
    if (!apply_filters('nibwp_replace_media_use_etch', false, $id, $input)) {
        return null;
    }
    // Active is not the same as recent: the route arrived in a later Etch.
    if (!nibwp_etchwp_has_replace_route()) {
        return null;
    }

    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        return null;
    }

    // Get the replacement onto disk first — the endpoint wants bytes, however
    // the caller described them.
    $tmp = null;
    if (!empty($input['url'])) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $tmp = download_url(esc_url_raw((string) $input['url']));
        if (is_wp_error($tmp)) {
            return null; // Let the native path report the failure properly.
        }
        $src = $tmp;
    } elseif (!empty($input['path']) && is_readable((string) $input['path'])) {
        $src = (string) $input['path'];
    } else {
        return null;
    }

    $name  = basename($src);
    $check = wp_check_filetype($name);
    if (empty($check['type'])) {
        if ($tmp) {
            @unlink($tmp);
        }
        return null;
    }

    $expires = time() + 300;
    $token   = WP_Session_Tokens::get_instance($user_id)->create($expires);
    $secure  = is_ssl();
    $auth    = wp_generate_auth_cookie($user_id, $expires, $secure ? 'secure_auth' : 'auth', $token);
    $logged  = wp_generate_auth_cookie($user_id, $expires, 'logged_in', $token);

    // The REST nonce is tied to the session token, and wp_create_nonce() reads
    // that token out of $_COOKIE. The cookie we just minted is not the one this
    // request arrived with, so it has to be in place while the nonce is made or
    // the far side sees an anonymous caller.
    $had   = $_COOKIE[LOGGED_IN_COOKIE] ?? null;
    $_COOKIE[LOGGED_IN_COOKIE] = $logged;
    $nonce = wp_create_nonce('wp_rest');
    if ($had === null) {
        unset($_COOKIE[LOGGED_IN_COOKIE]);
    } else {
        $_COOKIE[LOGGED_IN_COOKIE] = $had;
    }

    $boundary = wp_generate_password(24, false);
    $body = "--{$boundary}\r\n"
        . 'Content-Disposition: form-data; name="file"; filename="' . $name . "\"\r\n"
        . 'Content-Type: ' . $check['type'] . "\r\n\r\n"
        . (string) file_get_contents($src) . "\r\n"
        . "--{$boundary}--\r\n";

    if ($tmp) {
        @unlink($tmp);
    }

    $response = wp_remote_post(rest_url('etch-api/replace/' . $id), [
        'timeout' => 60,
        'headers' => [
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            'X-WP-Nonce'   => $nonce,
            'Cookie'       => ($secure ? SECURE_AUTH_COOKIE : AUTH_COOKIE) . '=' . $auth
                . '; ' . LOGGED_IN_COOKIE . '=' . $logged,
        ],
        'body' => $body,
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return null; // Stand aside; the native path runs and reports for real.
    }

    clean_post_cache($id);
    $file = get_attached_file($id);

    return [
        'attachment_id' => $id,
        'url'           => wp_get_attachment_url($id),
        'file'          => (string) $file,
        'url_preserved' => true,
        'bytes'         => (int) @filesize((string) $file),
        'handler'       => 'etch',
    ];
}

/**
 * Whether this install's Etch exposes the replace route.
 *
 * Checked against the registered routes rather than a version number: the route
 * is what matters, and Etch has shipped it without a constant to test.
 */
function nibwp_etchwp_has_replace_route(): bool
{
    foreach (array_keys(rest_get_server()->get_routes()) as $route) {
        if (strpos($route, '/etch-api/replace/') === 0) {
            return true;
        }
    }

    return false;
}
