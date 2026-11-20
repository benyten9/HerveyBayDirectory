<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: Gutenverse.
 *
 * Gutenverse is a block library — sixty-odd Gutenberg blocks with very large
 * attribute sets. The whole point of this hub is that an agent building a page
 * should never guess an attribute name. Every attribute reported here is read out
 * of WP_Block_Type_Registry at runtime, so what comes back is what this install
 * will actually accept, not what some version's documentation said.
 *
 * Verified against Gutenverse 4.0.9 (gutenverse.php:18) carrying framework 3.0.9
 * (lib/framework/bootstrap.php:18).
 *
 * THREE THINGS THIS VERSION DOES NOT HAVE, found by reading it rather than
 * assuming, and each one left out on purpose:
 *
 * 1. It registers NO post type of its own. `register_post_type` does not appear
 *    anywhere in the plugin. There is no template CPT, no section CPT, no popup
 *    CPT — a popup is the gutenverse/popup-builder block sitting inside an
 *    ordinary post or template (block/popup-builder/block.json:3), and its
 *    display rules are block attributes (openTrigger, openWaitTime,
 *    openScrollDistance, condition), not post meta. So there is nothing here to
 *    create or delete, and no "kind" meta to establish.
 *
 * 2. Its template and section LIBRARY is remote. The editor is handed
 *    GUTENVERSE_FRAMEWORK_LIBRARY_URL . 'wp-json/gutenverse-server/v1'
 *    (lib/framework/includes/class-editor-assets.php:116), which points at
 *    gutenverse.com (lib/framework/bootstrap.php:23-24). What lives locally is
 *    bookmarks — liked_layout and liked_section inside the
 *    `gutenverse-meta-option` option (lib/framework/includes/class-meta-option.php:85-86)
 *    — plus whatever a person imported, which lands in core's own wp_template and
 *    wp_template_part posts (lib/framework/helper.php:1595). list_templates and
 *    get_template read those, and they are READ-ONLY here: deleting a wp_template
 *    silently reverts a theme template, which is WordPress's decision to offer,
 *    not a block library's.
 *
 * 3. Per-page custom CSS and JS are stored but never rendered. The framework's
 *    REST routes save them to `gutenverse_page_custom_css` /
 *    `gutenverse_page_custom_js` and read them back for the editor
 *    (lib/framework/includes/class-api.php:2063-2097), and nothing anywhere in
 *    4.0.9 prints them on the front end. get_page_custom_code reports what is
 *    stored AND says so, because an owner who pasted CSS there and cannot see it
 *    working deserves the real answer. Nothing here writes them: a write that
 *    quietly has no effect is worse than no write at all.
 *
 * SECRETS: `gutenverse-license` holds the Pro licence key
 * (lib/framework/includes/class-api.php:773) and `gutenverse-meta-option` holds
 * the owner's subscription email (class-meta-option.php:88). Both are reported as
 * set-or-not, never as a value, and they are not in any writable settings group.
 */

/** Changing site-wide Gutenverse configuration. */
const NIBWP_GV_CAP_ADMIN = 'manage_options';

/**
 * Reading the block registry and finding where blocks are used.
 *
 * Lower than manage_options because this is the editor's own knowledge — an
 * author who may build a page may read what the blocks accept. Each individual
 * post is still checked with edit_post on that post.
 */
const NIBWP_GV_CAP_BUILD = 'edit_posts';

/** Every block this plugin registers is namespaced. */
const NIBWP_GV_NS = 'gutenverse/';

/** How many rows any listing returns, whatever was asked for. */
const NIBWP_GV_MAX_ROWS = 200;

/** The site-wide settings blob (lib/framework/includes/class-options.php:64). */
const NIBWP_GV_SETTINGS = 'gutenverse-settings';

/** Global typography/colour defaults the editor saves (class-editor-assets.php:109). */
const NIBWP_GV_GLOBAL_STYLE = 'gutenverse-global-setting';

/** Plugin bookkeeping, including the subscription email (class-meta-option.php:25). */
const NIBWP_GV_META = 'gutenverse-meta-option';

/** The Pro licence key. Never returned (class-api.php:773). */
const NIBWP_GV_LICENSE = 'gutenverse-license';

/** The generated-CSS cache id (lib/framework/includes/class-frontend-cache.php:33). */
const NIBWP_GV_CACHE_ID = 'gutenverse-style-cache-id';

/**
 * Is Gutenverse actually loaded?
 *
 * The constant is defined by the main plugin file before anything else is pulled
 * in (gutenverse.php:18), and the main class is instantiated at the bottom of it
 * (gutenverse.php:37). Between them they tell "not installed" apart from
 * "installed and half-booted".
 */
function nibwp_gutenverse_guard(): ?WP_Error
{
    if (!defined('GUTENVERSE_VERSION') && !class_exists('Gutenverse\\Gutenverse')) {
        return new WP_Error(
            'nibwp_gutenverse_missing',
            __('Gutenverse is not active on this site.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/** A refusal about the request itself. */
function nibwp_gutenverse_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error('nibwp_gutenverse_' . $code, $message, ['status' => $status]);
}

/** A refusal about authority rather than about the request. */
function nibwp_gutenverse_forbidden(string $what, string $cap): WP_Error
{
    return new WP_Error(
        'nibwp_gutenverse_forbidden',
        sprintf(
            /* translators: 1: what was being attempted, 2: capability name */
            __('%1$s needs the %2$s capability, which this account does not have.', 'nibwp'),
            $what,
            $cap
        ),
        ['status' => 403]
    );
}

/** The site-wide gate. */
function nibwp_gutenverse_require(string $what, string $cap): ?WP_Error
{
    return current_user_can($cap) ? null : nibwp_gutenverse_forbidden($what, $cap);
}

/**
 * The gate for one specific post.
 *
 * edit_posts says an account may write posts of some type. It says nothing about
 * THIS post, and a Gutenverse-built page is usually somebody's live homepage, so
 * the question asked is always edit_post against the id.
 */
function nibwp_gutenverse_require_post(int $id, string $what): ?WP_Error
{
    return current_user_can('edit_post', $id)
        ? null
        : nibwp_gutenverse_forbidden($what, 'edit_post');
}

/** Clamp a row count that arrived over the wire. */
function nibwp_gutenverse_limit(mixed $value, int $default = 50): int
{
    $limit = is_numeric($value) ? (int) $value : $default;

    return max(1, min(NIBWP_GV_MAX_ROWS, $limit));
}

/**
 * The registered Gutenverse blocks, straight from the registry.
 *
 * Not from the filesystem, and this is not a style preference. 4.0.9 ships seven
 * block.json files it never registers — block/query-loop, block/post-template,
 * block/dynamic-field and the four block/dynamic-pagination* directories appear
 * in no register_block_type() call in the plugin and in no JavaScript either,
 * and the classes their `class_callback` names do not exist. A list built by
 * scanning ./block would advertise seven blocks that cannot be inserted and
 * would render nothing if they were. The registry is the only honest source.
 *
 * @return array<string, object> block name => WP_Block_Type
 */
function nibwp_gutenverse_registry(): array
{
    if (!class_exists('WP_Block_Type_Registry')) {
        return [];
    }

    try {
        $registry = WP_Block_Type_Registry::get_instance();

        if (!is_object($registry) || !method_exists($registry, 'get_all_registered')) {
            return [];
        }

        $all = (array) $registry->get_all_registered();
    } catch (\Throwable $error) {
        return [];
    }

    $blocks = [];

    foreach ($all as $name => $type) {
        if (is_string($name) && str_starts_with($name, NIBWP_GV_NS) && is_object($type)) {
            $blocks[$name] = $type;
        }
    }

    ksort($blocks);

    return $blocks;
}

/**
 * Normalise a block name that arrived from an agent.
 *
 * "heading" and "gutenverse/heading" both mean the same thing and agents send
 * both. A name in somebody else's namespace is refused rather than answered:
 * this hub speaks for Gutenverse, and quietly introspecting core or a third
 * party's blocks through it would be a surprise.
 */
function nibwp_gutenverse_block_name(mixed $raw): string|WP_Error
{
    $name = is_string($raw) ? strtolower(trim($raw)) : '';

    if ($name === '') {
        return nibwp_gutenverse_err(
            'missing_block',
            __('Give the block as block, either "heading" or "gutenverse/heading".', 'nibwp'),
            422
        );
    }

    if (!str_contains($name, '/')) {
        $name = NIBWP_GV_NS . $name;
    }

    if (!str_starts_with($name, NIBWP_GV_NS)) {
        return nibwp_gutenverse_err(
            'foreign_block',
            sprintf(
                /* translators: %s: the block name that was asked for */
                __('"%s" is not a Gutenverse block. This hub only reports blocks in the gutenverse/ namespace.', 'nibwp'),
                $name
            ),
            422
        );
    }

    return $name;
}

/**
 * One block's attribute schema, as the registry holds it.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_gutenverse_attributes(object $type): array
{
    $attributes = property_exists($type, 'attributes') ? $type->attributes : null;

    if (!is_array($attributes)) {
        return [];
    }

    $rows = [];

    foreach ($attributes as $key => $spec) {
        $spec = is_array($spec) ? $spec : [];

        $rows[] = [
            'name'    => (string) $key,
            'type'    => isset($spec['type']) && is_scalar($spec['type']) ? (string) $spec['type'] : null,
            'default' => array_key_exists('default', $spec) && (is_scalar($spec['default']) || is_array($spec['default']) || $spec['default'] === null)
                ? $spec['default']
                : null,
            'has_default' => array_key_exists('default', $spec),
            'enum'    => isset($spec['enum']) && is_array($spec['enum']) ? array_values($spec['enum']) : null,
            // A "source" attribute is scraped out of the saved HTML rather than
            // stored in the block comment, so an agent writing block markup has
            // to put it in the markup, not in the attributes JSON.
            'source'  => isset($spec['source']) && is_scalar($spec['source']) ? (string) $spec['source'] : null,
        ];
    }

    return $rows;
}

/**
 * The summary row for one block.
 *
 * @return array<string, mixed>
 */
function nibwp_gutenverse_block_row(string $name, object $type): array
{
    $attributes = property_exists($type, 'attributes') && is_array($type->attributes) ? $type->attributes : [];

    return [
        'name'            => $name,
        'title'           => property_exists($type, 'title') && is_scalar($type->title) ? (string) $type->title : '',
        'category'        => property_exists($type, 'category') && is_scalar($type->category) ? (string) $type->category : null,
        // Everything except gutenverse/social-share is registered with a
        // render_callback (includes/class-blocks.php:113-130), so it renders at
        // request time and its saved markup is only a placeholder.
        'dynamic'         => property_exists($type, 'render_callback') && $type->render_callback !== null,
        'attribute_count' => count($attributes),
    ];
}

/**
 * The block categories this build adds to the inserter.
 *
 * @return array<int, array<string, string>>
 */
function nibwp_gutenverse_categories(): array
{
    return [
        [
            'slug'  => 'gutenverse-structure',
            'label' => __('Gutenverse Wrapper', 'nibwp'),
            'note'  => __('Layout: section, wrapper, column, container, and the popup blocks.', 'nibwp'),
        ],
        [
            'slug'  => 'gutenverse-element',
            'label' => __('Gutenverse Element', 'nibwp'),
            'note'  => __('Content blocks: headings, buttons, images, galleries, tabs, accordions and the rest.', 'nibwp'),
        ],
        [
            'slug'  => 'gutenverse-post',
            'label' => __('Gutenverse Post', 'nibwp'),
            'note'  => __('Blocks that read the current post or a query: post title, excerpt, terms, post block, post list.', 'nibwp'),
        ],
    ];
}

/**
 * Every Gutenverse block delimiter in a chunk of saved post content.
 *
 * Done with a regex over the serialised markup rather than parse_blocks(),
 * because the question is about the BYTES that were saved. parse_blocks() on a
 * block the registry no longer knows still yields a block — it just has no type
 * — and the audit below exists precisely to find those, so going through the
 * parser would launder away the thing being looked for.
 *
 * The attribute JSON is matched lazily up to a brace that is immediately
 * followed by the comment close, which is what makes nested objects come back
 * whole instead of truncated at the first inner brace.
 *
 * @return array<string, array<string, mixed>> block name => count + attribute keys
 */
function nibwp_gutenverse_scan(string $content): array
{
    $found = [];

    if ($content === '') {
        return $found;
    }

    $pattern = '/<!--\s+wp:(' . preg_quote(NIBWP_GV_NS, '/') . '[a-z0-9-]+)\s*(\{.*?\})?\s*\/?-->/s';

    if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER) === false) {
        return $found;
    }

    foreach ($matches as $match) {
        $name = (string) ($match[1] ?? '');

        if ($name === '') {
            continue;
        }

        if (!isset($found[$name])) {
            $found[$name] = ['count' => 0, 'attributes' => []];
        }

        $found[$name]['count']++;

        $json = (string) ($match[2] ?? '');

        if ($json === '') {
            continue;
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            continue;
        }

        foreach (array_keys($decoded) as $key) {
            $found[$name]['attributes'][(string) $key] = true;
        }
    }

    ksort($found);

    return $found;
}

/**
 * Posts whose saved content contains a given needle.
 *
 * Narrowing with LIKE first and confirming with the scan afterwards: the LIKE is
 * what keeps this off a full table read, and the scan is what makes the answer
 * exact.
 *
 * @param array<int, string> $types Restrict to these post types, or [] for all.
 * @return array<int, array<string, mixed>>
 */
function nibwp_gutenverse_posts_like(string $needle, int $limit, array $types = []): array
{
    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results') || !method_exists($wpdb, 'prepare')) {
        return [];
    }

    // The only values that ever reach this clause are the two literals below,
    // chosen by an enum in the caller. Nothing from input is interpolated.
    $allowed = ['wp_template', 'wp_template_part'];
    $filter = '';

    foreach ($types as $type) {
        if (in_array($type, $allowed, true)) {
            $filter .= ($filter === '' ? '' : ', ') . "'" . $type . "'";
        }
    }

    $sql = $wpdb->prepare(
        'SELECT ID, post_title, post_name, post_type, post_status, post_content FROM ' . $wpdb->posts
        . " WHERE post_status NOT IN ('auto-draft', 'inherit', 'trash')"
        . ($filter === '' ? '' : ' AND post_type IN (' . $filter . ')')
        . ' AND post_content LIKE %s'
        . ' ORDER BY ID DESC LIMIT %d',
        '%' . $wpdb->esc_like($needle) . '%',
        $limit
    );

    return (array) $wpdb->get_results($sql, ARRAY_A);
}

/**
 * The site-wide settings blob.
 *
 * @return array<string, mixed>
 */
function nibwp_gutenverse_settings(): array
{
    $settings = get_option(NIBWP_GV_SETTINGS, []);

    return is_array($settings) ? $settings : [];
}

/**
 * The settings groups this build reads, and whether this hub will write them.
 *
 * custom_font is deliberately not writable. The plugin's own save path writes a
 * stylesheet into the uploads directory for every custom font as it stores the
 * option (lib/framework/includes/class-api.php:1954-1990); setting the option
 * alone would leave the site pointing at a font file that was never generated.
 *
 * @return array<string, array<string, mixed>>
 */
function nibwp_gutenverse_setting_groups(): array
{
    return [
        'editor_settings' => [
            'writable' => true,
            'keys'     => ['tablet_breakpoint', 'mobile_breakpoint', 'autoblock_recovery', 'missing_block_warn'],
            'note'     => __('Editor behaviour: the two responsive breakpoints, automatic block recovery, and the missing-block warning.', 'nibwp'),
        ],
        'frontend_settings' => [
            'writable' => true,
            'keys'     => ['render_mechanism', 'file_delete_mechanism', 'old_render_deletion_schedule', 'remove_template_part_margin', 'disable_wp_lazyload'],
            'note'     => __('How generated CSS reaches the front end, and two layout compatibility switches.', 'nibwp'),
        ],
        'performance' => [
            'writable' => true,
            'keys'     => ['default_image_load'],
            'note'     => __('default_image_load is lazy or eager, and applies to every Gutenverse image that does not set its own.', 'nibwp'),
        ],
        'general' => [
            'writable' => true,
            'keys'     => ['enable_default_template'],
            'note'     => __('Whether the plugin registers its default templates.', 'nibwp'),
        ],
        'custom_font' => [
            'writable' => false,
            'keys'     => ['value'],
            'note'     => __('Read-only here. Saving a custom font also writes a stylesheet into the uploads directory, and setting the option on its own would leave the site asking for a file that does not exist. Use the plugin\'s own screen.', 'nibwp'),
        ],
    ];
}

/**
 * Reduce a value that arrived over the wire to scalars, arrays of scalars, and
 * nothing else.
 */
function nibwp_gutenverse_sanitize(mixed $value, int $depth = 0): mixed
{
    if ($depth > 6) {
        return null;
    }

    if (is_array($value)) {
        $clean = [];

        foreach ($value as $key => $item) {
            $clean[is_int($key) ? $key : sanitize_key((string) $key)] = nibwp_gutenverse_sanitize($item, $depth + 1);
        }

        return $clean;
    }

    if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
        return $value;
    }

    return is_scalar($value) ? sanitize_text_field((string) $value) : null;
}

/**
 * The breakpoints the plugin will actually use.
 *
 * The defaults are hard-coded in the helper rather than stored, so an empty
 * option means 1024 and 767 and not "unset" (lib/framework/helper.php:1903-1920).
 *
 * @return array<string, int>
 */
function nibwp_gutenverse_breakpoints(): array
{
    $editor = nibwp_gutenverse_settings()['editor_settings'] ?? [];
    $editor = is_array($editor) ? $editor : [];

    $tablet = isset($editor['tablet_breakpoint']) && (int) $editor['tablet_breakpoint'] > 0
        ? (int) $editor['tablet_breakpoint']
        : 1024;
    $mobile = isset($editor['mobile_breakpoint']) && (int) $editor['mobile_breakpoint'] > 0
        ? (int) $editor['mobile_breakpoint']
        : 767;

    return ['tablet' => $tablet, 'mobile' => $mobile];
}

/**
 * The global-style keys this build's generator knows how to apply.
 *
 * Listed from Global_Style::generate() (lib/framework/includes/class-global-style.php:30-539)
 * so an owner reading get_global_style can tell a key that means something from
 * a key some older version left behind.
 *
 * @return array<int, string>
 */
function nibwp_gutenverse_global_style_keys(): array
{
    return [
        'background',
        'bodyTextColor', 'bodyTypography', 'paragraphSpacing',
        'linkTextColor', 'linkTypography',
        'h1TextColor', 'h1Typography', 'h2TextColor', 'h2Typography', 'h3TextColor', 'h3Typography',
        'h4TextColor', 'h4Typography', 'h5TextColor', 'h5Typography', 'h6TextColor', 'h6Typography',
        'buttonTextColor', 'buttonTextColorHover', 'buttonTypography', 'buttonBackground',
        'buttonBackgroundHover', 'buttonBorder', 'buttonBorderHover', 'buttonBoxShadow',
        'buttonBoxShadowHover', 'buttonPadding', 'buttonTextShadow',
        'inputTextColor', 'inputTextColorFocus', 'inputTypography', 'inputBgColor', 'inputBgColorFocus',
        'inputBorder', 'inputBorderFocus', 'inputBoxShadow', 'inputBoxShadowFocus', 'inputPadding',
        'inputHelperColor', 'inputHelperTypography',
        'formTextColor', 'formTypography',
        'imageBorder', 'imageBorderHover', 'imageBoxShadow', 'imageBoxShadowHover',
        'imageOpacity', 'imageOpacityHover',
    ];
}

/** The framework's cache object, if this install got far enough to build one. */
function nibwp_gutenverse_cache(): ?object
{
    $init = 'Gutenverse\\Framework\\Init';

    if (!class_exists($init) || !method_exists($init, 'instance')) {
        return null;
    }

    try {
        $instance = $init::instance();
    } catch (\Throwable $error) {
        return null;
    }

    if (!is_object($instance) || !property_exists($instance, 'frontend_cache')) {
        return null;
    }

    return is_object($instance->frontend_cache) ? $instance->frontend_cache : null;
}

/**
 * The actions this hub offers.
 *
 * @return array<int, string>
 */
function nibwp_gutenverse_actions(): array
{
    return [
        // Orientation.
        'overview',
        // The block registry — what to build with, and with which attributes.
        'list_blocks',
        'get_block',
        'list_block_categories',
        // Where blocks are used, and where they have stopped working.
        'find_block_usage',
        'audit_unregistered_blocks',
        'audit_block_attributes',
        // Site-wide configuration.
        'get_settings',
        'get_breakpoints',
        'get_global_variables',
        'get_global_style',
        'library_sources',
        'cache_status',
        // Templates and parts, read-only — they are WordPress's post types.
        'list_templates',
        'get_template',
        'get_page_custom_code',
        // Writes — three, all site-wide, all manage_options.
        'update_settings',
        'set_breakpoints',
        'flush_style_cache',
    ];
}

wp_register_ability('nibwp/gutenverse-manage', [
    'label' => __('Gutenverse – Blocks & Global Settings', 'nibwp'),
    'description' => 'Work with Gutenverse, a Gutenberg block library. Lists every block this install actually registers and reports each block\'s real attribute schema out of the block registry, '
        . 'so page markup can be written with correct attribute names instead of guessed ones. '
        . 'Reads the plugin\'s site-wide settings, the responsive breakpoints, the per-theme global colours and fonts, the global typography defaults, and the generated-CSS cache. '
        . 'Finds which posts use a given block; audits posts that still contain blocks this build no longer registers, which stay in the saved content and render nothing; '
        . 'and audits saved blocks carrying attributes the registry does not declare. '
        . 'Templates and template parts are read-only: Gutenverse registers no post type of its own, so its templates are WordPress\'s wp_template and wp_template_part posts. '
        . 'Three writes and no others: update a settings group, set the breakpoints, flush the generated-CSS cache. The Pro licence key and the subscription email are never returned.',
    'category' => 'builder',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => nibwp_gutenverse_actions(),
                'description' => 'Which operation to run. Start with overview.',
            ],
            'block' => ['type' => 'string', 'description' => 'A block name, "heading" or "gutenverse/heading". For get_block, find_block_usage, and optionally audit_block_attributes.'],
            'include_attributes' => ['type' => 'boolean', 'description' => 'For list_blocks: include every block\'s full attribute schema. Very large — prefer get_block for one block.'],
            'category' => ['type' => 'string', 'description' => 'For list_blocks: gutenverse-structure, gutenverse-element or gutenverse-post.'],
            'template_id' => ['type' => 'integer', 'description' => 'For get_template: the id of a wp_template or wp_template_part post.'],
            'post_id' => ['type' => 'integer', 'description' => 'For get_page_custom_code: the post whose stored Gutenverse CSS/JS to read.'],
            'group' => ['type' => 'string', 'description' => 'For update_settings: which settings group to patch. get_settings lists them and says which are writable.'],
            'values' => ['type' => 'object', 'description' => 'For update_settings: the keys to merge into that group. Keys not named are left alone.'],
            'tablet' => ['type' => 'integer', 'description' => 'For set_breakpoints: the tablet breakpoint in pixels. Default 1024.'],
            'mobile' => ['type' => 'integer', 'description' => 'For set_breakpoints: the mobile breakpoint in pixels. Default 767.'],
            'limit' => ['type' => 'integer', 'description' => 'Rows to return. Default 50, max 200.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string'],
        ],
        'required' => ['action'],
    ],
    'execute_callback' => 'nibwp_gutenverse_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Gutenverse: a Gutenberg block library with very large attribute sets.',
                '',
                'START WITH overview. It says which version is installed, how many blocks are',
                'registered, which settings groups exist, and whether anything on the site is',
                'carrying a block this build cannot render.',
                '',
                'NEVER GUESS AN ATTRIBUTE NAME. Call get_block for the block you are about to',
                'write and use the names it returns. They come out of the block registry on',
                'this install, so they are what this version will accept. Some blocks carry',
                'over two hundred attributes and nearly all of them are optional; set the few',
                'you mean and leave the rest out so the block keeps its own defaults.',
                '',
                'An attribute whose "source" is set is read out of the saved HTML, not out of',
                'the block comment, so it has to appear in the markup rather than in the',
                'attributes JSON.',
                '',
                'MOST OF THESE BLOCKS ARE DYNAMIC: they are registered with a render callback,',
                'so what the editor saves is a placeholder and the real HTML is produced on',
                'each request. Reading the saved markup tells you what the block is set to, not',
                'what a visitor sees.',
                '',
                'WHAT THIS CANNOT DO, by design:',
                'Gutenverse 4.0.9 registers no post type of its own — no template, section or',
                'popup post type exists, so there is nothing here to create or delete. A popup',
                'is the gutenverse/popup-builder block inside an ordinary post or template, and',
                'its triggers and conditions are block attributes. Its template and section',
                'LIBRARY is remote, on the vendor\'s server; what is local is bookmarks and',
                'whatever somebody already imported, which lands in WordPress\'s own',
                'wp_template and wp_template_part posts. Those are read-only here: deleting one',
                'silently reverts a theme template. Do not look for another action; there is',
                'not one, and saying so is the correct answer.',
                '',
                'THE THREE WRITES, all site-wide and all needing manage_options:',
                '- update_settings patches one group. Read get_settings first; it says which',
                '  groups may be written and why custom_font may not.',
                '- set_breakpoints moves the tablet and mobile breakpoints, which changes how',
                '  every responsive value on the site is interpreted. Say so before doing it.',
                '- flush_style_cache throws away generated CSS so it is rebuilt. Safe, but the',
                '  next few page loads do more work.',
                '',
                'AUDITS: audit_unregistered_blocks finds posts that still contain a Gutenverse',
                'block this build does not register — usually a Pro block on a site whose Pro',
                'plugin was deactivated. The markup is still in the post and the block renders',
                'nothing, so the page has a hole in it that the editor will not point out.',
                'audit_block_attributes finds saved blocks carrying attribute names the',
                'registry no longer declares; those values are ignored at render time.',
                '',
                'LISTINGS DROP WHAT THIS ACCOUNT MAY NOT EDIT and report the number dropped as',
                '"skipped". A listing that looks short is usually that, not an empty site.',
                '',
                'SECRETS: the Pro licence key and the subscription email are reported as set or',
                'not set, never as values, and asking differently will not change that.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gutenverse_manage(array $input): array|WP_Error
{
    $guard = nibwp_gutenverse_guard();
    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $action = isset($input['action']) ? (string) $input['action'] : '';

    if (!in_array($action, nibwp_gutenverse_actions(), true)) {
        return nibwp_gutenverse_err(
            'unknown_action',
            sprintf(
                /* translators: 1: action name, 2: list of valid actions */
                __('Unknown Gutenverse action "%1$s". Valid actions: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_gutenverse_actions())
            )
        );
    }

    // The plugin's framework reaches the filesystem for cache statistics and
    // builds its own singletons lazily, both of which throw on a half-upgraded
    // install. One catch here turns any of that into a named refusal instead of a
    // fatal that takes the whole REST request with it.
    try {
        return nibwp_gutenverse_dispatch($action, $input);
    } catch (\Throwable $error) {
        return nibwp_gutenverse_err(
            'plugin_error',
            sprintf(
                /* translators: 1: action name, 2: error message from the plugin */
                __('Gutenverse could not complete %1$s: %2$s', 'nibwp'),
                $action,
                $error->getMessage()
            ),
            500
        );
    }
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gutenverse_dispatch(string $action, array $input): array|WP_Error
{
    switch ($action) {
        case 'overview':
            $result = nibwp_gutenverse_overview();
            break;
        case 'list_blocks':
            $result = nibwp_gutenverse_list_blocks($input);
            break;
        case 'get_block':
            $result = nibwp_gutenverse_get_block($input);
            break;
        case 'list_block_categories':
            $result = nibwp_gutenverse_list_block_categories();
            break;
        case 'find_block_usage':
            $result = nibwp_gutenverse_find_block_usage($input);
            break;
        case 'audit_unregistered_blocks':
            $result = nibwp_gutenverse_audit_unregistered($input);
            break;
        case 'audit_block_attributes':
            $result = nibwp_gutenverse_audit_attributes($input);
            break;
        case 'get_settings':
            $result = nibwp_gutenverse_get_settings();
            break;
        case 'get_breakpoints':
            $result = nibwp_gutenverse_get_breakpoints();
            break;
        case 'get_global_variables':
            $result = nibwp_gutenverse_get_global_variables();
            break;
        case 'get_global_style':
            $result = nibwp_gutenverse_get_global_style();
            break;
        case 'library_sources':
            $result = nibwp_gutenverse_library_sources();
            break;
        case 'cache_status':
            $result = nibwp_gutenverse_cache_status();
            break;
        case 'list_templates':
            $result = nibwp_gutenverse_list_templates($input);
            break;
        case 'get_template':
            $result = nibwp_gutenverse_get_template($input);
            break;
        case 'get_page_custom_code':
            $result = nibwp_gutenverse_get_page_custom_code($input);
            break;
        case 'update_settings':
            $result = nibwp_gutenverse_update_settings($input);
            break;
        case 'set_breakpoints':
            $result = nibwp_gutenverse_set_breakpoints($input);
            break;
        case 'flush_style_cache':
            $result = nibwp_gutenverse_flush_style_cache();
            break;
        default:
            $result = nibwp_gutenverse_err('unhandled_action', __('Unhandled action.', 'nibwp'));
    }

    return $result instanceof WP_Error ? $result : ['action' => $action] + $result;
}

/* -------------------------------------------------------------------- reads */

/**
 * Where this install stands, in one response.
 *
 * Built from the low-level helpers rather than by calling the other actions, so
 * one capability is asked for once instead of each action re-asking.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gutenverse_overview(): array|WP_Error
{
    if ($denied = nibwp_gutenverse_require(__('Reading the Gutenverse overview', 'nibwp'), NIBWP_GV_CAP_ADMIN)) {
        return $denied;
    }

    $blocks = nibwp_gutenverse_registry();
    $by_category = [];

    foreach ($blocks as $name => $type) {
        $row = nibwp_gutenverse_block_row($name, $type);
        $key = (string) ($row['category'] ?? 'uncategorised');
        $by_category[$key] = ($by_category[$key] ?? 0) + 1;
    }

    $settings = nibwp_gutenverse_settings();
    $stale = nibwp_gutenverse_stale_block_names(NIBWP_GV_MAX_ROWS);

    $act_on = [];

    if ($blocks === []) {
        $act_on[] = __('No Gutenverse block is registered. The plugin is present but its blocks never reached the registry, so nothing it provides can be inserted.', 'nibwp');
    }
    if ($stale !== []) {
        $act_on[] = sprintf(
            /* translators: %s: comma-separated block names */
            __('Saved content still contains blocks this build does not register (%s). They stay in the post and render nothing. Run audit_unregistered_blocks.', 'nibwp'),
            implode(', ', array_slice($stale, 0, 8))
        );
    }

    return [
        'plugin_version' => defined('GUTENVERSE_VERSION') ? (string) constant('GUTENVERSE_VERSION') : null,
        'framework_version' => defined('GUTENVERSE_FRAMEWORK_VERSION') ? (string) constant('GUTENVERSE_FRAMEWORK_VERSION') : null,
        'blocks_registered' => count($blocks),
        'blocks_by_category' => $by_category,
        'settings_groups_present' => array_values(array_filter(
            array_keys(nibwp_gutenverse_setting_groups()),
            static fn (string $group): bool => isset($settings[$group])
        )),
        'breakpoints' => nibwp_gutenverse_breakpoints(),
        'active_theme' => get_stylesheet(),
        'pro_licence_key_set' => nibwp_gutenverse_license_set(),
        'pro_licence_key_returned' => false,
        'registers_own_post_type' => false,
        'act_on' => $act_on,
        'looks_reasonable' => $act_on === [],
        'note' => __('Gutenverse registers no post type of its own in this version: its templates are WordPress\'s wp_template and wp_template_part posts, and a popup is a block inside one of those. Call get_block before writing any block markup.', 'nibwp'),
    ];
}

/** Whether a Pro licence key is stored. The value itself never travels. */
function nibwp_gutenverse_license_set(): bool
{
    $license = get_option(NIBWP_GV_LICENSE, '');

    return is_string($license) && trim($license) !== '';
}

/**
 * Block names present in saved content that the registry does not know.
 *
 * Shared by overview and the audit so the two cannot disagree.
 *
 * @return array<int, string>
 */
function nibwp_gutenverse_stale_block_names(int $limit): array
{
    $registry = nibwp_gutenverse_registry();
    $stale = [];

    foreach (nibwp_gutenverse_posts_like('wp:' . NIBWP_GV_NS, $limit) as $row) {
        foreach (array_keys(nibwp_gutenverse_scan((string) ($row['post_content'] ?? ''))) as $name) {
            if (!isset($registry[$name])) {
                $stale[$name] = true;
            }
        }
    }

    $names = array_keys($stale);
    sort($names);

    return $names;
}

/**
 * Every block this install registers.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gutenverse_list_blocks(array $input): array|WP_Error
{
    if ($denied = nibwp_gutenverse_require(__('Reading the Gutenverse block list', 'nibwp'), NIBWP_GV_CAP_BUILD)) {
        return $denied;
    }

    $wanted = isset($input['category']) ? strtolower(trim((string) $input['category'])) : '';
    $full = !empty($input['include_attributes']);
    $rows = [];

    foreach (nibwp_gutenverse_registry() as $name => $type) {
        $row = nibwp_gutenverse_block_row($name, $type);

        if ($wanted !== '' && (string) ($row['category'] ?? '') !== $wanted) {
            continue;
        }

        if ($full) {
            $row['attributes'] = nibwp_gutenverse_attributes($type);
        }

        $rows[] = $row;
    }

    return [
        'count' => count($rows),
        'blocks' => $rows,
        'attributes_included' => $full,
        'source' => 'WP_Block_Type_Registry',
        'note' => $full
            ? __('Every attribute here came out of the block registry on this install, so these are the names this version accepts.', 'nibwp')
            : __('Attribute schemas are left out because some blocks carry over two hundred attributes. Call get_block for the one you are about to write.', 'nibwp'),
    ];
}

/**
 * One block, with its full attribute schema.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gutenverse_get_block(array $input): array|WP_Error
{
    if ($denied = nibwp_gutenverse_require(__('Reading a Gutenverse block schema', 'nibwp'), NIBWP_GV_CAP_BUILD)) {
        return $denied;
    }

    $name = nibwp_gutenverse_block_name($input['block'] ?? null);
    if ($name instanceof WP_Error) {
        return $name;
    }

    $registry = nibwp_gutenverse_registry();

    if (!isset($registry[$name])) {
        return nibwp_gutenverse_err(
            'unknown_block',
            sprintf(
                /* translators: %s: the block name that was asked for */
                __('"%s" is not registered on this site. It may belong to an add-on that is not active, or it may have been removed. list_blocks has the ones that are here.', 'nibwp'),
                $name
            ),
            404
        );
    }

    $type = $registry[$name];
    $row = nibwp_gutenverse_block_row($name, $type);
    $attributes = nibwp_gutenverse_attributes($type);

    $sourced = array_values(array_filter(
        $attributes,
        static fn (array $attribute): bool => $attribute['source'] !== null
    ));

    return $row + [
        'description' => property_exists($type, 'description') && is_scalar($type->description) ? (string) $type->description : '',
        'keywords' => property_exists($type, 'keywords') && is_array($type->keywords) ? array_values($type->keywords) : [],
        'supports' => property_exists($type, 'supports') && is_array($type->supports) ? $type->supports : [],
        'parent' => property_exists($type, 'parent') && is_array($type->parent) ? array_values($type->parent) : null,
        'attributes' => $attributes,
        'attributes_read_from_markup' => array_column($sourced, 'name'),
        'source' => 'WP_Block_Type_Registry',
        'note' => $row['dynamic'] === true
            ? __('This block renders through a callback, so the markup saved in the post is a placeholder and the HTML a visitor sees is built on each request.', 'nibwp')
            : __('This block saves its own markup, so what is in the post is what a visitor gets.', 'nibwp'),
    ];
}

/**
 * The inserter categories this build adds.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gutenverse_list_block_categories(): array|WP_Error
{
    if ($denied = nibwp_gutenverse_require(__('Reading the Gutenverse block categories', 'nibwp'), NIBWP_GV_CAP_BUILD)) {
        return $denied;
    }

    $counts = [];

    foreach (nibwp_gutenverse_registry() as $name => $type) {
        $row = nibwp_gutenverse_block_row($name, $type);
        $key = (string) ($row['category'] ?? 'uncategorised');
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    $rows = [];

    foreach (nibwp_gutenverse_categories() as $category) {
        $rows[] = $category + ['registered_blocks' => (int) ($counts[$category['slug']] ?? 0)];
    }

    return [
        'categories' => $rows,
        'other_categories_seen' => array_values(array_diff(
            array_keys($counts),
            array_column(nibwp_gutenverse_categories(), 'slug')
        )),
    ];
}

/**
 * Which posts use a given block.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gutenverse_find_block_usage(array $input): array|WP_Error
{
    if ($denied = nibwp_gutenverse_require(__('Searching for Gutenverse block usage', 'nibwp'), NIBWP_GV_CAP_BUILD)) {
        return $denied;
    }

    $name = nibwp_gutenverse_block_name($input['block'] ?? null);
    if ($name instanceof WP_Error) {
        return $name;
    }

    $registry = nibwp_gutenverse_registry();
    $limit = nibwp_gutenverse_limit($input['limit'] ?? null);
    $rows = [];
    $skipped = 0;
    $instances = 0;

    foreach (nibwp_gutenverse_posts_like('wp:' . $name, $limit) as $row) {
        $id = (int) ($row['ID'] ?? 0);
        $found = nibwp_gutenverse_scan((string) ($row['post_content'] ?? ''));

        // The LIKE is a prefix match, so a longer block name whose text happens
        // to contain this one would come back too. The scan is what decides.
        if (!isset($found[$name])) {
            continue;
        }

        // Dropped, not hidden. An account that may not edit a post has no
        // business being told its title either.
        if (!current_user_can('edit_post', $id)) {
            $skipped++;
            continue;
        }

        $instances += (int) $found[$name]['count'];

        $rows[] = [
            'id' => $id,
            'title' => (string) ($row['post_title'] ?? ''),
            'post_type' => (string) ($row['post_type'] ?? ''),
            'status' => (string) ($row['post_status'] ?? ''),
            'instances' => (int) $found[$name]['count'],
            'attributes_set' => array_keys($found[$name]['attributes']),
        ];
    }

    return [
        'block' => $name,
        'registered' => isset($registry[$name]),
        'posts' => $rows,
        'returned' => count($rows),
        'instances' => $instances,
        'skipped' => $skipped,
        'skipped_reason' => $skipped > 0
            ? __('Posts this account may not edit were left out of the list and counted here.', 'nibwp')
            : null,
        'limited_to' => $limit,
        'note' => isset($registry[$name])
            ? __('attributes_set names only the attributes actually written into each block\'s markup; everything else is at the block\'s default.', 'nibwp')
            : __('This block is NOT registered on this site, so every instance listed here renders nothing.', 'nibwp'),
    ];
}

/**
 * Posts carrying blocks this build no longer registers.
 *
 * The usual cause is an add-on that was deactivated: the markup stays in the
 * post, the editor shows an invalid-content warning if somebody opens it, and
 * the front end renders nothing at all where the block was. Nobody is told.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gutenverse_audit_unregistered(array $input): array|WP_Error
{
    if ($denied = nibwp_gutenverse_require(__('Auditing unregistered Gutenverse blocks', 'nibwp'), NIBWP_GV_CAP_BUILD)) {
        return $denied;
    }

    $registry = nibwp_gutenverse_registry();
    $limit = nibwp_gutenverse_limit($input['limit'] ?? null);
    $rows = [];
    $skipped = 0;
    $names = [];

    foreach (nibwp_gutenverse_posts_like('wp:' . NIBWP_GV_NS, $limit) as $row) {
        $id = (int) ($row['ID'] ?? 0);
        $missing = [];

        foreach (nibwp_gutenverse_scan((string) ($row['post_content'] ?? '')) as $name => $details) {
            if (isset($registry[$name])) {
                continue;
            }

            $missing[] = ['block' => $name, 'instances' => (int) $details['count']];
            $names[$name] = ($names[$name] ?? 0) + (int) $details['count'];
        }

        if ($missing === []) {
            continue;
        }

        if (!current_user_can('edit_post', $id)) {
            $skipped++;
            continue;
        }

        $rows[] = [
            'id' => $id,
            'title' => (string) ($row['post_title'] ?? ''),
            'post_type' => (string) ($row['post_type'] ?? ''),
            'status' => (string) ($row['post_status'] ?? ''),
            'missing_blocks' => $missing,
        ];
    }

    ksort($names);

    return [
        'blocks' => array_map(
            static fn (string $name, int $count): array => ['block' => $name, 'instances' => $count],
            array_keys($names),
            array_values($names)
        ),
        'posts' => $rows,
        'returned' => count($rows),
        'skipped' => $skipped,
        'skipped_reason' => $skipped > 0
            ? __('Posts this account may not edit were left out of the list and counted here, so a post may be affected without appearing above.', 'nibwp')
            : null,
        'limited_to' => $limit,
        'clean' => $rows === [] && $skipped === 0,
        'note' => __('A block the registry does not know renders nothing. The markup is still in the post, so the fix is to activate whatever provided the block or edit the post — not to delete content from here.', 'nibwp'),
    ];
}

/**
 * Saved blocks carrying attributes the registry does not declare.
 *
 * Reported rather than dropped: a value WordPress ignores at render time is
 * exactly the kind of thing an owner spends an afternoon on, because the editor
 * shows the old setting and the page does not honour it.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gutenverse_audit_attributes(array $input): array|WP_Error
{
    if ($denied = nibwp_gutenverse_require(__('Auditing Gutenverse block attributes', 'nibwp'), NIBWP_GV_CAP_BUILD)) {
        return $denied;
    }

    $only = '';

    if (isset($input['block'])) {
        $name = nibwp_gutenverse_block_name($input['block']);
        if ($name instanceof WP_Error) {
            return $name;
        }
        $only = $name;
    }

    $registry = nibwp_gutenverse_registry();
    $limit = nibwp_gutenverse_limit($input['limit'] ?? null);
    $needle = $only === '' ? 'wp:' . NIBWP_GV_NS : 'wp:' . $only;
    $rows = [];
    $skipped = 0;
    $unknown = [];

    foreach (nibwp_gutenverse_posts_like($needle, $limit) as $row) {
        $id = (int) ($row['ID'] ?? 0);
        $problems = [];

        foreach (nibwp_gutenverse_scan((string) ($row['post_content'] ?? '')) as $name => $details) {
            if ($only !== '' && $name !== $only) {
                continue;
            }

            // A block that is not registered at all is the other audit's
            // business; reporting every one of its attributes as unknown here
            // would bury the real findings.
            if (!isset($registry[$name])) {
                continue;
            }

            $declared = array_column(nibwp_gutenverse_attributes($registry[$name]), 'name');
            $extra = array_values(array_diff(array_keys($details['attributes']), $declared));

            if ($extra === []) {
                continue;
            }

            $problems[] = ['block' => $name, 'unknown_attributes' => $extra];

            foreach ($extra as $attribute) {
                $unknown[$name . '::' . $attribute] = true;
            }
        }

        if ($problems === []) {
            continue;
        }

        if (!current_user_can('edit_post', $id)) {
            $skipped++;
            continue;
        }

        $rows[] = [
            'id' => $id,
            'title' => (string) ($row['post_title'] ?? ''),
            'post_type' => (string) ($row['post_type'] ?? ''),
            'status' => (string) ($row['post_status'] ?? ''),
            'problems' => $problems,
        ];
    }

    $pairs = array_keys($unknown);
    sort($pairs);

    return [
        'block' => $only === '' ? null : $only,
        'unknown_attributes' => $pairs,
        'posts' => $rows,
        'returned' => count($rows),
        'skipped' => $skipped,
        'skipped_reason' => $skipped > 0
            ? __('Posts this account may not edit were left out of the list and counted here.', 'nibwp')
            : null,
        'limited_to' => $limit,
        'clean' => $rows === [] && $skipped === 0,
        'checked_against' => 'WP_Block_Type_Registry',
        'note' => __('These attribute names are in the saved markup but are not declared by the block on this install, so they are ignored when the block renders. Usually a block that dropped or renamed a setting between versions.', 'nibwp'),
    ];
}

/**
 * The site-wide settings, grouped, with the licence withheld.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gutenverse_get_settings(): array|WP_Error
{
    if ($denied = nibwp_gutenverse_require(__('Reading the Gutenverse settings', 'nibwp'), NIBWP_GV_CAP_ADMIN)) {
        return $denied;
    }

    $settings = nibwp_gutenverse_settings();
    $known = nibwp_gutenverse_setting_groups();
    $rows = [];

    foreach ($known as $group => $meta) {
        $rows[] = [
            'group' => $group,
            'writable' => (bool) $meta['writable'],
            'present' => isset($settings[$group]),
            'keys_this_build_reads' => $meta['keys'],
            'values' => isset($settings[$group]) && is_array($settings[$group]) ? $settings[$group] : [],
            'note' => $meta['note'],
        ];
    }

    return [
        'option' => NIBWP_GV_SETTINGS,
        'groups' => $rows,
        // Groups an older version or an add-on left behind. Named, not valued:
        // nothing here knows what they contain or whether it is sensitive.
        'other_groups_stored' => array_values(array_diff(array_keys($settings), array_keys($known))),
        'withheld' => [
            [
                'option' => NIBWP_GV_LICENSE,
                'is_set' => nibwp_gutenverse_license_set(),
                'value_returned' => false,
                'what' => __('The Pro licence key.', 'nibwp'),
            ],
        ],
        'note' => __('Only the groups marked writable may be changed through update_settings. The licence key lives in its own option and is never returned.', 'nibwp'),
    ];
}

/**
 * The two responsive breakpoints, with the defaults made explicit.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gutenverse_get_breakpoints(): array|WP_Error
{
    if ($denied = nibwp_gutenverse_require(__('Reading the Gutenverse breakpoints', 'nibwp'), NIBWP_GV_CAP_ADMIN)) {
        return $denied;
    }

    $editor = nibwp_gutenverse_settings()['editor_settings'] ?? [];
    $editor = is_array($editor) ? $editor : [];
    $resolved = nibwp_gutenverse_breakpoints();

    return [
        'tablet' => $resolved['tablet'],
        'mobile' => $resolved['mobile'],
        'tablet_is_default' => empty($editor['tablet_breakpoint']),
        'mobile_is_default' => empty($editor['mobile_breakpoint']),
        'defaults' => ['tablet' => 1024, 'mobile' => 767],
        'stored_at' => NIBWP_GV_SETTINGS . '.editor_settings',
        'note' => __('An unset breakpoint is not "none" — the plugin falls back to 1024 and 767 in code, so the site behaves as if those were saved. Every responsive value on every Gutenverse block is interpreted against these two numbers.', 'nibwp'),
    ];
}

/**
 * The global colours and fonts, which are scoped to the active theme.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gutenverse_get_global_variables(): array|WP_Error
{
    if ($denied = nibwp_gutenverse_require(__('Reading the Gutenverse global variables', 'nibwp'), NIBWP_GV_CAP_ADMIN)) {
        return $denied;
    }

    // Three of the four options carry the stylesheet name in their key, so
    // switching theme switches the whole palette. Worth saying out loud: an owner
    // who changed theme and lost their colours has not lost them.
    $theme = (string) get_stylesheet();
    $colors = get_option('gutenverse-global-variable-color-' . $theme, []);
    $fonts = get_option('gutenverse-global-variable-font-' . $theme, []);
    $google = get_option('gutenverse-global-variable-google-' . $theme, []);
    $legacy = get_option('gutenverse-global-variable', []);
    $legacy = is_array($legacy) ? $legacy : [];

    return [
        'theme' => $theme,
        'options' => [
            'colors' => 'gutenverse-global-variable-color-' . $theme,
            'fonts' => 'gutenverse-global-variable-font-' . $theme,
            'google_fonts' => 'gutenverse-global-variable-google-' . $theme,
            'legacy' => 'gutenverse-global-variable',
        ],
        'colors' => is_array($colors) ? array_values($colors) : [],
        'fonts' => is_array($fonts) ? array_values($fonts) : [],
        'google_fonts' => is_array($google) ? array_values($google) : [],
        'legacy_colors' => isset($legacy['colors']) && is_array($legacy['colors']) ? count($legacy['colors']) : 0,
        'legacy_fonts' => isset($legacy['fonts']) && is_array($legacy['fonts']) ? count($legacy['fonts']) : 0,
        // The editor shows the theme's own palette alongside these
        // (lib/framework/includes/class-editor-assets.php:113), so a count of it
        // is the difference between "no colours anywhere" and "the theme's".
        'theme_palette_count' => count((array) wp_get_global_settings(['color', 'palette'])),
        'note' => __('These are per-theme: the option key carries the active stylesheet name, so a theme switch gives the site a different palette rather than losing the old one. The plugin also merges a legacy, theme-independent option when it is not empty.', 'nibwp'),
    ];
}

/**
 * The global typography and colour defaults the editor saves.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gutenverse_get_global_style(): array|WP_Error
{
    if ($denied = nibwp_gutenverse_require(__('Reading the Gutenverse global style settings', 'nibwp'), NIBWP_GV_CAP_ADMIN)) {
        return $denied;
    }

    $stored = get_option(NIBWP_GV_GLOBAL_STYLE, []);
    $stored = is_array($stored) ? $stored : [];
    $known = nibwp_gutenverse_global_style_keys();
    $keys = array_map('strval', array_keys($stored));

    return [
        'option' => NIBWP_GV_GLOBAL_STYLE,
        'is_set' => $stored !== [],
        'keys_stored' => $keys,
        'keys_recognised' => array_values(array_intersect($keys, $known)),
        'keys_not_recognised' => array_values(array_diff($keys, $known)),
        'values' => $stored,
        // Worth being blunt about: this build reads the option once, to hand it
        // to the editor, and the class that turns it into CSS is never
        // instantiated anywhere in 4.0.9. So whatever is set here styles the
        // editor's own controls and nothing on the front end.
        'applied_on_front_end' => false,
        'note' => __('In this version the option is read once and handed to the editor, and the class that would turn it into front-end CSS is never used. Treat what is stored here as an editor default, not as site styling — if the owner expects these to show on the site, that is the answer.', 'nibwp'),
    ];
}

/**
 * Where templates and sections come from on this install.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gutenverse_library_sources(): array|WP_Error
{
    if ($denied = nibwp_gutenverse_require(__('Reading the Gutenverse library sources', 'nibwp'), NIBWP_GV_CAP_ADMIN)) {
        return $denied;
    }

    $meta = get_option(NIBWP_GV_META, []);
    $meta = is_array($meta) ? $meta : [];
    $liked_layout = isset($meta['liked_layout']) && is_array($meta['liked_layout']) ? $meta['liked_layout'] : [];
    $liked_section = isset($meta['liked_section']) && is_array($meta['liked_section']) ? $meta['liked_section'] : [];

    return [
        'registers_own_post_type' => false,
        'library_is_remote' => true,
        // Reported, never requested. Nothing in this hub makes an outbound call.
        'library_base_url' => defined('GUTENVERSE_FRAMEWORK_LIBRARY_URL')
            ? (string) constant('GUTENVERSE_FRAMEWORK_LIBRARY_URL')
            : null,
        'local_storage' => [
            'imported_templates' => 'wp_template',
            'imported_parts' => 'wp_template_part',
            'bookmarks_option' => NIBWP_GV_META,
        ],
        'bookmarked_layouts' => count($liked_layout),
        'bookmarked_sections' => count($liked_section),
        'withheld' => [
            [
                'key' => NIBWP_GV_META . '.subscribed_email',
                'is_set' => isset($meta['subscribed_email']) && is_string($meta['subscribed_email']) && $meta['subscribed_email'] !== '',
                'value_returned' => false,
                'what' => __('The address the owner subscribed with.', 'nibwp'),
            ],
            [
                'key' => NIBWP_GV_LICENSE,
                'is_set' => nibwp_gutenverse_license_set(),
                'value_returned' => false,
                'what' => __('The Pro licence key.', 'nibwp'),
            ],
        ],
        'note' => __('The template and section library lives on the vendor\'s server; this install keeps only bookmarks to it. Anything already imported is a WordPress wp_template or wp_template_part post — list_templates reads those. Nothing here contacts the vendor.', 'nibwp'),
    ];
}

/**
 * The state of the generated-CSS cache.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gutenverse_cache_status(): array|WP_Error
{
    if ($denied = nibwp_gutenverse_require(__('Reading the Gutenverse cache status', 'nibwp'), NIBWP_GV_CAP_ADMIN)) {
        return $denied;
    }

    $frontend = nibwp_gutenverse_settings()['frontend_settings'] ?? [];
    $frontend = is_array($frontend) ? $frontend : [];
    $cache = nibwp_gutenverse_cache();
    $stats = null;
    $payload_enabled = null;

    if ($cache !== null && method_exists($cache, 'get_payload_cache_stats')) {
        try {
            $raw = (array) $cache->get_payload_cache_stats();
            $stats = [
                'files' => (int) ($raw['files'] ?? 0),
                'size' => (string) ($raw['size_label'] ?? ''),
                'directory_exists' => (bool) ($raw['exists'] ?? false),
            ];
        } catch (\Throwable $error) {
            $stats = null;
        }
    }

    if ($cache !== null && method_exists($cache, 'payload_cache_enabled')) {
        try {
            $payload_enabled = (bool) $cache->payload_cache_enabled();
        } catch (\Throwable $error) {
            $payload_enabled = null;
        }
    }

    return [
        'cache_id' => (string) get_option(NIBWP_GV_CACHE_ID, 'initial-cache'),
        'cache_id_option' => NIBWP_GV_CACHE_ID,
        'render_mechanism' => isset($frontend['render_mechanism']) && is_scalar($frontend['render_mechanism'])
            ? (string) $frontend['render_mechanism']
            : null,
        'file_delete_mechanism' => isset($frontend['file_delete_mechanism']) && is_scalar($frontend['file_delete_mechanism'])
            ? (string) $frontend['file_delete_mechanism']
            : null,
        'payload_cache_enabled' => $payload_enabled,
        'payload_cache' => $stats,
        'note' => $cache === null
            ? __('The plugin\'s cache object is not available in this request, so only the stored cache id could be read. The framework builds it on init; a request that ran before init will see nothing.', 'nibwp')
            : __('flush_style_cache throws this away so it is rebuilt. Safe to do, but the next few page loads do more work.', 'nibwp'),
    ];
}

/**
 * The post types that hold Gutenverse templates and sections.
 *
 * Both are WordPress's own, not the plugin's. Named in one place so the type
 * check and the listing cannot disagree about what counts.
 *
 * @return array<int, string>
 */
function nibwp_gutenverse_template_types(): array
{
    return ['wp_template', 'wp_template_part'];
}

/**
 * Templates and template parts that contain Gutenverse blocks.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gutenverse_list_templates(array $input): array|WP_Error
{
    if ($denied = nibwp_gutenverse_require(__('Listing Gutenverse templates', 'nibwp'), NIBWP_GV_CAP_BUILD)) {
        return $denied;
    }

    $limit = nibwp_gutenverse_limit($input['limit'] ?? null);
    $rows = [];
    $skipped = 0;

    foreach (nibwp_gutenverse_posts_like('wp:' . NIBWP_GV_NS, $limit, nibwp_gutenverse_template_types()) as $row) {
        $id = (int) ($row['ID'] ?? 0);

        if (!current_user_can('edit_post', $id)) {
            $skipped++;
            continue;
        }

        $found = nibwp_gutenverse_scan((string) ($row['post_content'] ?? ''));

        $rows[] = [
            'id' => $id,
            'title' => (string) ($row['post_title'] ?? ''),
            'slug' => (string) ($row['post_name'] ?? ''),
            'post_type' => (string) ($row['post_type'] ?? ''),
            'status' => (string) ($row['post_status'] ?? ''),
            'kind' => nibwp_gutenverse_template_kind($id, (string) ($row['post_type'] ?? '')),
            'gutenverse_blocks' => count($found),
            'gutenverse_block_names' => array_keys($found),
        ];
    }

    return [
        'templates' => $rows,
        'returned' => count($rows),
        'skipped' => $skipped,
        'skipped_reason' => $skipped > 0
            ? __('Templates this account may not edit were left out of the list and counted here.', 'nibwp')
            : null,
        'limited_to' => $limit,
        'post_types' => nibwp_gutenverse_template_types(),
        'read_only' => true,
        'note' => __('These are WordPress\'s own post types, not Gutenverse\'s — the plugin registers none. What distinguishes a part is the wp_template_part_area term (header, footer, sidebar, uncategorized), and the wp_theme term is what scopes either to a theme; neither is post meta. Read-only here on purpose: deleting one of these silently reverts the theme\'s own template.', 'nibwp'),
    ];
}

/**
 * Which kind of template this is, and which theme it belongs to.
 *
 * The answer is in taxonomy terms, not in post meta: core puts the part's area in
 * `wp_template_part_area` and the owning theme in `wp_theme`. Saying this
 * precisely matters, because an agent told to look in post meta will find
 * nothing and conclude the template is unscoped.
 *
 * @return array<string, mixed>
 */
function nibwp_gutenverse_template_kind(int $id, string $post_type): array
{
    $area = null;
    $theme = null;

    if ($post_type === 'wp_template_part') {
        $terms = get_the_terms($id, 'wp_template_part_area');

        if (is_array($terms)) {
            foreach ($terms as $term) {
                if (is_object($term) && property_exists($term, 'name')) {
                    $area = (string) $term->name;
                    break;
                }
            }
        }
    }

    $themes = get_the_terms($id, 'wp_theme');

    if (is_array($themes)) {
        foreach ($themes as $term) {
            if (is_object($term) && property_exists($term, 'name')) {
                $theme = (string) $term->name;
                break;
            }
        }
    }

    return [
        'is_part' => $post_type === 'wp_template_part',
        'area' => $area,
        'area_from' => 'wp_template_part_area taxonomy',
        'theme' => $theme,
        'theme_from' => 'wp_theme taxonomy',
        'from_post_meta' => false,
    ];
}

/**
 * One template or part, with the Gutenverse blocks it contains.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gutenverse_get_template(array $input): array|WP_Error
{
    $id = isset($input['template_id']) ? (int) $input['template_id'] : 0;

    if ($id <= 0) {
        return nibwp_gutenverse_err('missing_template_id', __('Give the template as template_id.', 'nibwp'), 422);
    }

    // The type check comes FIRST, before any capability and before the site-wide
    // gate. An id arriving from an agent is a claim, and the honest answer to the
    // id of an ordinary page is "that is not a template" whether or not the
    // caller could have edited it. Asking about the capability first would also
    // turn this into a way to probe which ids exist.
    $post_type = get_post_type($id);

    if (!in_array($post_type, nibwp_gutenverse_template_types(), true)) {
        return nibwp_gutenverse_err(
            'not_a_template',
            sprintf(
                /* translators: 1: post id, 2: comma-separated post type names */
                __('Post %1$d is not a template. template_id must be a %2$s post.', 'nibwp'),
                $id,
                implode(' or ', nibwp_gutenverse_template_types())
            ),
            404
        );
    }

    if ($denied = nibwp_gutenverse_require_post($id, __('Reading this template', 'nibwp'))) {
        return $denied;
    }

    $post = get_post($id);
    $content = is_object($post) && property_exists($post, 'post_content') ? (string) $post->post_content : '';
    $found = nibwp_gutenverse_scan($content);
    $registry = nibwp_gutenverse_registry();
    $blocks = [];

    foreach ($found as $name => $details) {
        $blocks[] = [
            'block' => $name,
            'instances' => (int) $details['count'],
            'registered' => isset($registry[$name]),
            'attributes_set' => array_keys($details['attributes']),
        ];
    }

    return [
        'id' => $id,
        'post_type' => (string) $post_type,
        'title' => is_object($post) && property_exists($post, 'post_title') ? (string) $post->post_title : '',
        'slug' => is_object($post) && property_exists($post, 'post_name') ? (string) $post->post_name : '',
        'status' => is_object($post) && property_exists($post, 'post_status') ? (string) $post->post_status : '',
        'kind' => nibwp_gutenverse_template_kind($id, (string) $post_type),
        'gutenverse_blocks' => $blocks,
        'content' => $content,
        'read_only' => true,
        'note' => __('Read-only. Deleting or rewriting one of these reverts or overrides the theme\'s own template, which is a decision for a person in the site editor.', 'nibwp'),
    ];
}

/**
 * The Gutenverse CSS and JS stored against one post.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gutenverse_get_page_custom_code(array $input): array|WP_Error
{
    $id = isset($input['post_id']) ? (int) $input['post_id'] : 0;

    if ($id <= 0) {
        return nibwp_gutenverse_err('missing_post_id', __('Give the post as post_id.', 'nibwp'), 422);
    }

    // Existence before authority, same reasoning as get_template: an id is a
    // claim, and this refusal must not depend on what the caller may do.
    if (get_post_type($id) === false) {
        return nibwp_gutenverse_err(
            'no_post',
            sprintf(
                /* translators: %d: post id */
                __('There is no post with id %d.', 'nibwp'),
                $id
            ),
            404
        );
    }

    if ($denied = nibwp_gutenverse_require_post($id, __('Reading the stored code for this post', 'nibwp'))) {
        return $denied;
    }

    $css = get_post_meta($id, 'gutenverse_page_custom_css', true);
    $js = get_post_meta($id, 'gutenverse_page_custom_js', true);

    return [
        'post_id' => $id,
        'post_type' => (string) get_post_type($id),
        'custom_css' => is_string($css) ? $css : '',
        'custom_js' => is_string($js) ? $js : '',
        'meta_keys' => ['gutenverse_page_custom_css', 'gutenverse_page_custom_js'],
        // The thing an owner actually needs to know.
        'rendered_on_front_end' => false,
        'writable_here' => false,
        'note' => __('Stored, but not printed. In this version the plugin saves these and reads them back for the editor, and nothing outputs them on the front end — so page CSS pasted here has no effect on the live page. Nothing in this hub writes them either: a write with no effect is worse than no write.', 'nibwp'),
    ];
}

/* ------------------------------------------------------------------- writes */

/**
 * Patch one settings group.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gutenverse_update_settings(array $input): array|WP_Error
{
    if ($denied = nibwp_gutenverse_require(__('Changing the Gutenverse settings', 'nibwp'), NIBWP_GV_CAP_ADMIN)) {
        return $denied;
    }

    $group = isset($input['group']) ? sanitize_key((string) $input['group']) : '';
    $known = nibwp_gutenverse_setting_groups();

    if ($group === '' || !isset($known[$group])) {
        return nibwp_gutenverse_err(
            'bad_group',
            sprintf(
                /* translators: 1: group name that was asked for, 2: comma-separated list of writable groups */
                __('"%1$s" is not a settings group this integration will write. Writable groups: %2$s.', 'nibwp'),
                $group,
                implode(', ', array_keys(array_filter(
                    $known,
                    static fn (array $meta): bool => (bool) $meta['writable']
                )))
            ),
            422
        );
    }

    if (!$known[$group]['writable']) {
        return nibwp_gutenverse_err('read_only_group', (string) $known[$group]['note'], 422);
    }

    $values = isset($input['values']) && is_array($input['values']) ? $input['values'] : [];

    if ($values === []) {
        return nibwp_gutenverse_err('nothing_to_change', __('Give the keys to change as values.', 'nibwp'), 422);
    }

    $settings = nibwp_gutenverse_settings();
    $current = isset($settings[$group]) && is_array($settings[$group]) ? $settings[$group] : [];
    $changed = [];

    // Merged, not replaced. The plugin's own save path overwrites a whole group
    // (lib/framework/includes/class-api.php:1945), which through an agent means a
    // caller who names one key silently clears the rest.
    foreach ($values as $key => $value) {
        $clean_key = sanitize_key((string) $key);

        if ($clean_key === '') {
            continue;
        }

        $current[$clean_key] = nibwp_gutenverse_sanitize($value);
        $changed[] = $clean_key;
    }

    if ($changed === []) {
        return nibwp_gutenverse_err('nothing_to_change', __('None of the keys given were usable.', 'nibwp'), 422);
    }

    $settings[$group] = $current;

    // Autoloaded, as the plugin stores it (class-api.php:1998). Storing it any
    // other way would make the plugin's own reads miss the cache.
    update_option(NIBWP_GV_SETTINGS, $settings, true);

    return [
        'updated' => true,
        'group' => $group,
        'changed' => $changed,
        'values' => $current,
        'note' => __('Generated CSS may still be cached from before this change — run flush_style_cache if the site does not reflect it.', 'nibwp'),
    ];
}

/**
 * Move the responsive breakpoints.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gutenverse_set_breakpoints(array $input): array|WP_Error
{
    if ($denied = nibwp_gutenverse_require(__('Changing the Gutenverse breakpoints', 'nibwp'), NIBWP_GV_CAP_ADMIN)) {
        return $denied;
    }

    $current = nibwp_gutenverse_breakpoints();
    $tablet = isset($input['tablet']) ? (int) $input['tablet'] : $current['tablet'];
    $mobile = isset($input['mobile']) ? (int) $input['mobile'] : $current['mobile'];

    if (!isset($input['tablet']) && !isset($input['mobile'])) {
        return nibwp_gutenverse_err('nothing_to_change', __('Give tablet, mobile, or both.', 'nibwp'), 422);
    }

    if ($tablet < 480 || $tablet > 2560) {
        return nibwp_gutenverse_err(
            'bad_breakpoint',
            sprintf(
                /* translators: %d: the rejected value */
                __('The tablet breakpoint must be between 480 and 2560 pixels; %d is not.', 'nibwp'),
                $tablet
            ),
            422
        );
    }

    if ($mobile < 240 || $mobile > 1280) {
        return nibwp_gutenverse_err(
            'bad_breakpoint',
            sprintf(
                /* translators: %d: the rejected value */
                __('The mobile breakpoint must be between 240 and 1280 pixels; %d is not.', 'nibwp'),
                $mobile
            ),
            422
        );
    }

    // The plugin reads these as two ceilings and never checks they are ordered.
    // Crossed over, every block's tablet rules and mobile rules fight each other
    // and the site looks broken at widths nobody tests.
    if ($mobile >= $tablet) {
        return nibwp_gutenverse_err(
            'bad_breakpoint',
            sprintf(
                /* translators: 1: mobile breakpoint, 2: tablet breakpoint */
                __('The mobile breakpoint (%1$d) must be smaller than the tablet breakpoint (%2$d).', 'nibwp'),
                $mobile,
                $tablet
            ),
            422
        );
    }

    $settings = nibwp_gutenverse_settings();
    $editor = isset($settings['editor_settings']) && is_array($settings['editor_settings'])
        ? $settings['editor_settings']
        : [];

    $editor['tablet_breakpoint'] = $tablet;
    $editor['mobile_breakpoint'] = $mobile;
    $settings['editor_settings'] = $editor;

    update_option(NIBWP_GV_SETTINGS, $settings, true);

    return [
        'updated' => true,
        'tablet' => $tablet,
        'mobile' => $mobile,
        'was' => $current,
        'note' => __('Every responsive value on every Gutenverse block is now read against these widths. Existing generated CSS still holds the old ones — run flush_style_cache.', 'nibwp'),
    ];
}

/**
 * Throw away generated CSS so it is rebuilt.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gutenverse_flush_style_cache(): array|WP_Error
{
    if ($denied = nibwp_gutenverse_require(__('Flushing the Gutenverse style cache', 'nibwp'), NIBWP_GV_CAP_ADMIN)) {
        return $denied;
    }

    $cache = nibwp_gutenverse_cache();

    if ($cache === null) {
        return nibwp_gutenverse_err(
            'no_cache_api',
            __('The plugin\'s cache object is not available in this request, so there is nothing to flush from here. The framework builds it on init.', 'nibwp'),
            500
        );
    }

    $done = [];

    if (method_exists($cache, 'cleanup_cached_style')) {
        $cache->cleanup_cached_style();
        $done[] = 'generated_css_files';
    }

    if (method_exists($cache, 'clear_payload_cache')) {
        $cache->clear_payload_cache();
        $done[] = 'payload_cache';
    }

    if ($done === []) {
        return nibwp_gutenverse_err(
            'no_cache_api',
            __('This version of Gutenverse does not expose a way to flush its generated CSS from here.', 'nibwp'),
            500
        );
    }

    return [
        'flushed' => $done,
        'note' => __('The CSS is rebuilt on the next request for each page, so the first visit to each one does more work than usual. Nothing was deleted that cannot be regenerated.', 'nibwp'),
    ];
}
