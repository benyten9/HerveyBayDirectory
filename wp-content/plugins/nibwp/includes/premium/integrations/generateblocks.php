<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: GenerateBlocks.
 *
 * Read-only over post content, on purpose. The reasoning is in
 * nibwp_gblocks_why_read_only() and repeated in the agent instructions, because
 * the first thing an agent will want to do with a block plugin is write a page
 * with it, and it needs to be told once, clearly, that this hub will not.
 *
 * What it is for instead: handing an agent the REAL attribute schema of every
 * block GenerateBlocks registers, so markup authored anywhere else uses the
 * attribute names the plugin actually reads rather than plausible guesses; and
 * telling the owner where those blocks already are, which of them are the
 * deprecated v1 generation, and which were left half-finished.
 *
 * Verified against GenerateBlocks 2.4.1 (plugin.php:8, GENERATEBLOCKS_VERSION at
 * plugin.php:22) on WordPress 7.1.2.
 *
 * WHAT THIS VERSION DOES NOT HAVE, so there is no action for it:
 *
 * - No custom post type of any kind. There is not a single register_post_type()
 *   call in the plugin, so there are no local patterns, no reusable styles, and
 *   no style library to read or write.
 * - No global style / global class STORE. `globalClasses` is an attribute on
 *   every v2 block (dist/blocks/element/block.json:35) and the class names are
 *   pasted onto the rendered element (includes/functions.php:2141-2146), but the
 *   definitions behind those names live in GenerateBlocks Pro — the free
 *   styles-builder bundle only references `generateblocks-pro/dist/assets/...`.
 *   So list_global_classes reports which class names content REFERS to, and says
 *   it cannot tell you what any of them mean.
 * - Patterns are not stored here either. `generateblocks_pattern_libraries`
 *   (includes/pattern-library/class-libraries.php:93) holds the ADDRESSES of
 *   remote libraries; the patterns themselves are fetched over HTTP from those
 *   domains. list_pattern_libraries reports the configured libraries and makes
 *   no outbound request.
 *
 * ONE HONEST LIMIT ON THE ATTRIBUTE SCHEMAS: the nine v2 blocks are registered
 * from block.json (includes/class-render-blocks.php:146-207), so the server
 * registry holds their full schema. The seven v1 blocks are registered with
 * title and render_callback only (includes/class-render-blocks.php:72-144) —
 * their attributes are declared in JavaScript, and WP_Block_Type::get_attributes()
 * hands back exactly what was registered. Every response that depends on a
 * schema says per block whether one exists, and the attribute audit skips the
 * blocks that have none rather than reporting all of their attributes as
 * unexpected.
 *
 * SECRETS: a remote pattern library carries a public_key
 * (includes/pattern-library/class-library-dto.php:35). It is withheld.
 */

/** Reading the block registry and finding blocks in content. */
const NIBWP_GBLOCKS_READ_CAP = 'edit_posts';

/** Anything plugin-wide: the settings option, the CSS cache, library config. */
const NIBWP_GBLOCKS_SETTINGS_CAP = 'manage_options';

/** How many posts any scan will look at, whatever was asked for. */
const NIBWP_GBLOCKS_MAX_ROWS = 200;

/** Every block this plugin registers is namespaced. */
const NIBWP_GBLOCKS_NS = 'generateblocks/';

/** How deep a block tree is walked before we stop and say so. */
const NIBWP_GBLOCKS_MAX_DEPTH = 32;

/**
 * Is GenerateBlocks actually loaded?
 *
 * The constant is defined at the top of the main plugin file (plugin.php:22) and
 * the options helper is loaded on the next line (plugin.php:27, functions.php),
 * so between them they tell "not installed" apart from "installed and half
 * loaded".
 */
function nibwp_gblocks_guard(): ?WP_Error
{
    if (!defined('GENERATEBLOCKS_VERSION') && !function_exists('generateblocks_get_option_defaults')) {
        return new WP_Error(
            'nibwp_gblocks_missing',
            __('GenerateBlocks is not active on this site.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/** A refusal about the request itself. */
function nibwp_gblocks_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error('nibwp_gblocks_' . $code, $message, ['status' => $status]);
}

/**
 * The boundary capability gate.
 *
 * Named separately from the per-post gate below, with its own error code, so a
 * test can tell which of the two refused. They are different failures and a
 * caller needs to know which one it hit: one means "this account cannot do this
 * kind of work at all", the other means "not on that post".
 */
function nibwp_gblocks_require(string $cap, string $what): ?WP_Error
{
    if (current_user_can($cap)) {
        return null;
    }

    return nibwp_gblocks_err(
        'forbidden',
        sprintf(
            /* translators: 1: what was being attempted, 2: capability name */
            __('%1$s needs the %2$s capability, which this account does not have.', 'nibwp'),
            $what,
            $cap
        ),
        403
    );
}

/**
 * The per-post gate.
 *
 * edit_posts is not enough for anything that names a post: it is the capability
 * to write SOME post, not that one. This is checked before the post is looked
 * up, which also means a post the account may not edit and a post that does not
 * exist refuse identically — otherwise the difference between the two messages
 * is a way to find out which ids exist.
 */
function nibwp_gblocks_require_post(int $post_id, string $what): ?WP_Error
{
    if ($post_id > 0 && current_user_can('edit_post', $post_id)) {
        return null;
    }

    return nibwp_gblocks_err(
        'post_forbidden',
        sprintf(
            /* translators: %s: what was being attempted */
            __('%s needs edit access to that specific post, which this account does not have.', 'nibwp'),
            $what
        ),
        403
    );
}

/** Why there is no write-post-content action here, in one place. */
function nibwp_gblocks_why_read_only(): string
{
    return __(
        'This hub does not write post content. A GenerateBlocks block is validated in the editor against the markup its JavaScript save() function would have produced, and that function is only in the compiled editor bundle — nothing on the PHP side can reproduce it or check against it. Two of the attributes are sourced from the markup rather than stored in the block comment (text content from .gb-text, the icon from .gb-shape), so even changing one attribute from PHP and re-serialising can leave the block valid on the front end and "unexpected or invalid content" in the editor. A page that renders but will not open is worse than no change at all, so content changes belong in the editor or in a tool that owns the save format.',
        'nibwp'
    );
}

/**
 * The seven v1 blocks.
 *
 * The plugin's own list is preferred (includes/functions.php:2287) so a later
 * version that adds to it is followed; the literal is the fallback for an
 * install where functions.php did not load.
 *
 * @return array<int, string>
 */
function nibwp_gblocks_v1_names(): array
{
    if (function_exists('generateblocks_get_v1_block_names')) {
        try {
            $names = generateblocks_get_v1_block_names();

            if (is_array($names) && $names !== []) {
                return array_values(array_map('strval', $names));
            }
        } catch (\Throwable $error) {
            // Fall through to the literal below.
        }
    }

    return [
        'generateblocks/button-container',
        'generateblocks/button',
        'generateblocks/container',
        'generateblocks/grid',
        'generateblocks/headline',
        'generateblocks/image',
        'generateblocks/query-loop',
    ];
}

/**
 * Every GenerateBlocks block type the server registry holds.
 *
 * @return array<string, object> name => WP_Block_Type
 */
function nibwp_gblocks_registered(): array
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

    $ours = [];

    foreach ($all as $name => $type) {
        if (is_string($name) && is_object($type) && str_starts_with($name, NIBWP_GBLOCKS_NS)) {
            $ours[$name] = $type;
        }
    }

    ksort($ours);

    return $ours;
}

/**
 * One block type's attribute schema, as the registry holds it.
 *
 * Deliberately not merged with anything: the point of these actions is to report
 * what the plugin registered, so an agent builds against that and not against a
 * list this file invented. An empty array is a true answer for the v1 blocks.
 *
 * @return array<string, mixed>
 */
function nibwp_gblocks_attributes(object $type): array
{
    try {
        if (method_exists($type, 'get_attributes')) {
            $attrs = $type->get_attributes();

            if (is_array($attrs)) {
                return $attrs;
            }
        }
    } catch (\Throwable $error) {
        // Fall through to the property.
    }

    return isset($type->attributes) && is_array($type->attributes) ? $type->attributes : [];
}

/**
 * Attributes the block editor may put on any block whatever the schema says.
 *
 * These are core's, not the plugin's, so finding one on a block is not a sign of
 * anything wrong and the audit does not report them.
 *
 * @return array<int, string>
 */
function nibwp_gblocks_tolerated_attributes(): array
{
    return ['lock', 'metadata', 'className', 'anchor', 'style'];
}

/**
 * Report one attribute the way these actions report attributes.
 *
 * @param mixed $schema
 * @return array<string, mixed>
 */
function nibwp_gblocks_attribute_row(string $name, $schema): array
{
    $schema = is_array($schema) ? $schema : [];

    $row = [
        'name' => $name,
        'type' => isset($schema['type']) && is_scalar($schema['type']) ? (string) $schema['type'] : null,
        'has_default' => array_key_exists('default', $schema),
        'default' => array_key_exists('default', $schema) ? $schema['default'] : null,
    ];

    // enum is the whole value of reading the registry rather than guessing:
    // tagName on a text block takes thirteen values and nothing else.
    if (isset($schema['enum']) && is_array($schema['enum'])) {
        $row['enum'] = array_values(array_filter($schema['enum'], 'is_scalar'));
    }

    // source + selector mean the attribute is read back out of the markup rather
    // than out of the block comment. That is exactly where hand-written markup
    // goes wrong, so it travels.
    foreach (['source', 'selector', 'role', 'attribute'] as $key) {
        if (isset($schema[$key]) && is_scalar($schema[$key])) {
            $row[$key] = (string) $schema[$key];
        }
    }

    return $row;
}

/**
 * Describe a block type without its attribute schema.
 *
 * @return array<string, mixed>
 */
function nibwp_gblocks_type_row(string $name, object $type): array
{
    $attributes = nibwp_gblocks_attributes($type);
    $is_v1 = in_array($name, nibwp_gblocks_v1_names(), true);

    return [
        'name' => $name,
        'title' => isset($type->title) && is_scalar($type->title) ? (string) $type->title : '',
        'generation' => $is_v1 ? 'v1' : 'v2',
        'api_version' => isset($type->api_version) && is_numeric($type->api_version) ? (int) $type->api_version : null,
        'category' => isset($type->category) && is_scalar($type->category) ? (string) $type->category : null,
        'attribute_count' => count($attributes),
        'has_attribute_schema' => $attributes !== [],
        'schema_note' => $attributes === []
            ? __('Registered with no attributes on the server, so this block\'s attribute names are not knowable from here. They are declared in the plugin\'s JavaScript.', 'nibwp')
            : null,
    ];
}

/**
 * Clamp a row count that arrived over the wire.
 *
 * @param mixed $value
 */
function nibwp_gblocks_limit($value, int $default = 50): int
{
    $limit = is_numeric($value) ? (int) $value : $default;

    return max(1, min(NIBWP_GBLOCKS_MAX_ROWS, $limit));
}

/**
 * Validate a block name that arrived from a caller.
 *
 * Checked against the registry rather than against a pattern: a name that looks
 * right but is not registered would otherwise produce an empty listing, which
 * reads as "nothing uses this block" when the truth is "no such block".
 *
 * @param mixed $raw
 */
function nibwp_gblocks_block_name($raw): string|WP_Error
{
    $name = is_string($raw) ? trim($raw) : '';

    if ($name === '') {
        return nibwp_gblocks_err('missing_block', __('Give the block name as block, for example generateblocks/text.', 'nibwp'), 422);
    }

    // A bare slug is accepted and namespaced, because an agent reading a page's
    // markup sees "generateblocks/text" but one reading prose sees "text".
    if (!str_contains($name, '/')) {
        $name = NIBWP_GBLOCKS_NS . $name;
    }

    $registered = nibwp_gblocks_registered();

    if (!isset($registered[$name])) {
        return nibwp_gblocks_err(
            'unknown_block',
            sprintf(
                /* translators: 1: the rejected block name, 2: list of registered block names */
                __('"%1$s" is not a block GenerateBlocks registers here. Registered: %2$s.', 'nibwp'),
                $name,
                $registered === [] ? __('none', 'nibwp') : implode(', ', array_keys($registered))
            ),
            422
        );
    }

    return $name;
}

/**
 * Candidate posts whose stored content mentions a GenerateBlocks delimiter.
 *
 * The LIKE is a prefilter and nothing more — every caller below confirms by
 * parsing, because a substring match cannot tell generateblocks/button from
 * generateblocks/button-container.
 *
 * Posts the account cannot edit are dropped here and COUNTED, so a listing never
 * silently shrinks: an agent that is quietly shown half a site draws wrong
 * conclusions from it and says them with confidence.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gblocks_candidates(array $input, string $needle): array|WP_Error
{
    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_col') || !method_exists($wpdb, 'prepare')) {
        return nibwp_gblocks_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    $limit = nibwp_gblocks_limit($input['limit'] ?? null);
    $offset = isset($input['offset']) ? max(0, (int) $input['offset']) : 0;
    $post_type = isset($input['post_type']) ? sanitize_key((string) $input['post_type']) : '';

    // Revisions and auto-drafts are not pages anybody is looking at, and an
    // inherit-status attachment's content is its description.
    $sql = 'SELECT ID FROM ' . $wpdb->posts
        . " WHERE post_type <> 'revision'"
        . " AND post_status NOT IN ('trash', 'auto-draft', 'inherit')"
        . ' AND post_content LIKE %s';

    $args = ['%' . $wpdb->esc_like($needle) . '%'];

    if ($post_type !== '') {
        $sql .= ' AND post_type = %s';
        $args[] = $post_type;
    }

    $sql .= ' ORDER BY ID ASC LIMIT %d OFFSET %d';
    $args[] = $limit + 1;
    $args[] = $offset;

    $rows = (array) $wpdb->get_col($wpdb->prepare($sql, $args));

    $truncated = count($rows) > $limit;

    if ($truncated) {
        array_pop($rows);
    }

    $ids = [];
    $skipped = 0;

    foreach ($rows as $row) {
        $id = (int) $row;

        if ($id <= 0) {
            continue;
        }

        if (!current_user_can('edit_post', $id)) {
            $skipped++;
            continue;
        }

        $ids[] = $id;
    }

    return [
        'ids' => $ids,
        'candidates' => count($rows),
        'skipped_no_permission' => $skipped,
        'limit' => $limit,
        'offset' => $offset,
        'truncated' => $truncated,
    ];
}

/**
 * The stored content of one post, or '' when there is none to read.
 */
function nibwp_gblocks_content(int $post_id): string
{
    $post = get_post($post_id);

    if (!is_object($post) || !isset($post->post_content) || !is_string($post->post_content)) {
        return '';
    }

    return $post->post_content;
}

/**
 * Parse stored content into a block tree, without letting the parser throw out.
 *
 * @return array<int, mixed>
 */
function nibwp_gblocks_parse(string $content): array
{
    if ($content === '' || !function_exists('parse_blocks')) {
        return [];
    }

    try {
        $blocks = parse_blocks($content);
    } catch (\Throwable $error) {
        return [];
    }

    return is_array($blocks) ? $blocks : [];
}

/**
 * Walk a parsed tree, calling $visit for every node including freeform ones.
 *
 * Freeform nodes are visited on purpose: a delimiter the parser could not read
 * ends up inside one, and that is the clearest signal available from PHP that
 * markup is broken.
 *
 * @param array<int, mixed> $blocks
 */
function nibwp_gblocks_walk(array $blocks, callable $visit, int $depth = 0): void
{
    if ($depth > NIBWP_GBLOCKS_MAX_DEPTH) {
        return;
    }

    foreach ($blocks as $block) {
        if (!is_array($block)) {
            continue;
        }

        $visit($block, $depth);

        if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
            nibwp_gblocks_walk($block['innerBlocks'], $visit, $depth + 1);
        }
    }
}

/**
 * Count each GenerateBlocks block in one post's parsed tree.
 *
 * @return array<string, int>
 */
function nibwp_gblocks_count_blocks(string $content): array
{
    $counts = [];

    nibwp_gblocks_walk(
        nibwp_gblocks_parse($content),
        function (array $block) use (&$counts): void {
            $name = isset($block['blockName']) && is_string($block['blockName']) ? $block['blockName'] : '';

            if ($name === '' || !str_starts_with($name, NIBWP_GBLOCKS_NS)) {
                return;
            }

            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }
    );

    return $counts;
}

/**
 * Every GenerateBlocks delimiter in raw stored content, read without the parser.
 *
 * Two reasons not to rely on parse_blocks() alone here. A malformed attribute
 * payload is silently turned into null by the core parser
 * (wp-includes/class-wp-block-parser.php:279-281), so the parsed tree cannot
 * tell "no attributes" from "attributes that are not JSON". And a delimiter the
 * parser rejected outright never appears as a block at all.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_gblocks_delimiters(string $content): array
{
    if ($content === '') {
        return [];
    }

    $pattern = '#<!--\s+(?P<closer>/)?wp:(?P<name>generateblocks/[A-Za-z0-9_-]+)'
        . '(?:\s+(?P<attrs>\{.*?\}))?\s*(?P<void>/)?-->#s';

    if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER) === false) {
        return [];
    }

    $found = [];

    foreach ((array) $matches as $match) {
        $attrs = isset($match['attrs']) ? (string) $match['attrs'] : '';
        $decoded = $attrs === '' ? [] : json_decode($attrs, true);

        $found[] = [
            'name' => (string) ($match['name'] ?? ''),
            'is_closer' => ($match['closer'] ?? '') === '/',
            'is_void' => ($match['void'] ?? '') === '/',
            'has_attrs' => $attrs !== '',
            'attrs_parse_ok' => $attrs === '' || is_array($decoded),
            'attrs' => is_array($decoded) ? $decoded : [],
        ];
    }

    return $found;
}

/**
 * The actions this hub offers. Every one of them reads.
 *
 * @return array<int, string>
 */
function nibwp_gblocks_actions(): array
{
    return [
        // The block registry — what to build with.
        'overview',
        'list_block_types',
        'get_block_type',
        'list_block_attributes',
        'list_dynamic_tags',
        // Content — where the blocks already are.
        'find_posts_using_block',
        'block_usage_report',
        'get_post_blocks',
        'list_global_classes',
        'audit_invalid_markup',
        'audit_placeholder_content',
        // Plugin-wide, manage_options.
        'get_settings',
        'update_settings',
        'get_block_defaults',
        'get_css_status',
        'regenerate_css',
        'list_pattern_libraries',
        'set_v1_blocks_available',
    ];
}

wp_register_ability('nibwp/generateblocks-manage', [
    'label' => __('GenerateBlocks – Blocks, Content and Settings', 'nibwp'),
    'description' => 'Read a GenerateBlocks site: every block type it registers with that block\'s real attribute schema taken from the server registry '
        . '(attribute names, types, defaults, enums, and which attributes are sourced from markup rather than stored), which posts and pages use a given block, '
        . 'what is still on the deprecated v1 blocks, which global class names content refers to, and the plugin\'s settings and CSS cache state. '
        . 'Two audits: posts whose block markup will not open cleanly in the editor, and blocks left with placeholder content. '
        . 'It does not write post content and has no action that does — block validity is decided by the editor\'s JavaScript, which PHP cannot reproduce. '
        . 'The only writes are the plugin\'s own settings, its CSS cache, and the v1-blocks switch.',
    'category' => 'content',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => nibwp_gblocks_actions(),
                'description' => 'Which operation to run. Start with overview.',
            ],
            'block' => ['type' => 'string', 'description' => 'For get_block_type / find_posts_using_block: a block name such as generateblocks/text. A bare slug is namespaced for you.'],
            'post_id' => ['type' => 'integer', 'description' => 'For get_post_blocks: the post or page.'],
            'post_type' => ['type' => 'string', 'description' => 'Narrow a content scan to one post type.'],
            'settings' => ['type' => 'object', 'description' => 'For update_settings: the keys to change. Unknown keys are refused, not ignored.'],
            'enabled' => ['type' => 'boolean', 'description' => 'For set_v1_blocks_available: whether the editor offers the v1 blocks.'],
            'limit' => ['type' => 'integer', 'description' => 'Posts to look at. Default 50, max 200.'],
            'offset' => ['type' => 'integer', 'description' => 'Where a scan starts.'],
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
    'execute_callback' => 'nibwp_generateblocks_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'GenerateBlocks. Read-only over post content.',
                '',
                'START WITH overview. It says which version is installed, how many blocks it',
                'registers, how many of those have a readable attribute schema, and whether the',
                'deprecated v1 blocks are still being offered in the editor.',
                '',
                'IT DOES NOT WRITE POST CONTENT, and there is no action that does. A block is',
                'validated in the editor against what its JavaScript save() function would have',
                'produced, and that function only exists in the compiled editor bundle. Two',
                'attributes are read back out of the markup rather than out of the block comment',
                '(text content from .gb-text, the icon from .gb-shape), so even a single-attribute',
                'change made from PHP can leave a page that renders perfectly and will not open in',
                'the editor. Do not look for another action, and do not offer to write the page',
                'another way: say that content changes belong in the editor.',
                '',
                'BUILDING MARKUP SOMEWHERE ELSE: get_block_type is the point of this hub. It',
                'returns the attribute schema from the server registry — names, types, defaults,',
                'the enum of allowed tagName values, and which attributes are sourced from the',
                'markup. Use it instead of guessing attribute names.',
                '',
                'THE TWO GENERATIONS: nine blocks (text, element, media, shape, query, looper,',
                'loop-item, query-no-results, query-page-numbers) are registered from block.json,',
                'so their schema is complete. Seven (container, grid, button, button-container,',
                'headline, image, query-loop) are the deprecated v1 generation, registered with no',
                'attributes on the server. For those, has_attribute_schema is false and that is',
                'the truth, not a failure — their attributes are declared in JavaScript. Say so',
                'rather than inventing them.',
                '',
                'WHAT IS NOT IN THIS VERSION: there are no local patterns and no reusable style',
                'objects — the plugin registers no post type at all. Global class NAMES appear on',
                'blocks and list_global_classes reports which ones content uses, but what a class',
                'means is stored by a separate product that is not installed here. Pattern',
                'libraries are remote addresses; list_pattern_libraries reports the configuration',
                'and fetches nothing.',
                '',
                'THE WRITES, all manage_options and none of them touching content:',
                '- update_settings changes container width, CSS print method, and two toggles.',
                '- regenerate_css empties the generated-CSS cache so it rebuilds on next load.',
                '- set_v1_blocks_available toggles whether the editor offers the v1 blocks. It',
                '  refuses to turn them OFF while any post still contains one, because those',
                '  blocks then stop being recognised in the editor. Clear them first.',
                '',
                'LISTINGS DROP POSTS THIS ACCOUNT CANNOT EDIT and report the number dropped as',
                'skipped_no_permission. A non-zero value there means the picture is partial — say',
                'so rather than reporting the visible part as the whole site.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_generateblocks_manage(array $input): array|WP_Error
{
    $guard = nibwp_gblocks_guard();
    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $action = isset($input['action']) ? (string) $input['action'] : '';

    if (!in_array($action, nibwp_gblocks_actions(), true)) {
        return nibwp_gblocks_err(
            'unknown_action',
            sprintf(
                /* translators: 1: action name, 2: list of valid actions */
                __('Unknown GenerateBlocks action "%1$s". Valid actions: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_gblocks_actions())
            )
        );
    }

    // The plugin's helpers reach the database, run filters other plugins have
    // hooked, and on a half-updated install can raise. One catch here turns any
    // of that into a named refusal rather than a fatal that takes the whole REST
    // request with it.
    try {
        return nibwp_gblocks_dispatch($action, $input);
    } catch (\Throwable $error) {
        return nibwp_gblocks_err(
            'plugin_error',
            sprintf(
                /* translators: 1: action name, 2: error message from the plugin */
                __('GenerateBlocks could not complete %1$s: %2$s', 'nibwp'),
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
function nibwp_gblocks_dispatch(string $action, array $input): array|WP_Error
{
    $result = match ($action) {
        'overview' => nibwp_gblocks_overview(),
        'list_block_types' => nibwp_gblocks_list_block_types(),
        'get_block_type' => nibwp_gblocks_get_block_type($input),
        'list_block_attributes' => nibwp_gblocks_list_block_attributes(),
        'list_dynamic_tags' => nibwp_gblocks_list_dynamic_tags(),
        'find_posts_using_block' => nibwp_gblocks_find_posts_using_block($input),
        'block_usage_report' => nibwp_gblocks_block_usage_report($input),
        'get_post_blocks' => nibwp_gblocks_get_post_blocks($input),
        'list_global_classes' => nibwp_gblocks_list_global_classes($input),
        'audit_invalid_markup' => nibwp_gblocks_audit_invalid_markup($input),
        'audit_placeholder_content' => nibwp_gblocks_audit_placeholder_content($input),
        'get_settings' => nibwp_gblocks_get_settings(),
        'update_settings' => nibwp_gblocks_update_settings($input),
        'get_block_defaults' => nibwp_gblocks_get_block_defaults(),
        'get_css_status' => nibwp_gblocks_get_css_status(),
        'regenerate_css' => nibwp_gblocks_regenerate_css(),
        'list_pattern_libraries' => nibwp_gblocks_list_pattern_libraries(),
        'set_v1_blocks_available' => nibwp_gblocks_set_v1_blocks_available($input),
        default => nibwp_gblocks_err('unhandled_action', __('Unhandled action.', 'nibwp')),
    };

    return $result instanceof WP_Error ? $result : ['action' => $action] + $result;
}

/* --------------------------------------------------------- the block registry */

/**
 * What is installed and what it offers, in one response.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gblocks_overview(): array|WP_Error
{
    if ($denied = nibwp_gblocks_require(NIBWP_GBLOCKS_READ_CAP, __('Reading the GenerateBlocks overview', 'nibwp'))) {
        return $denied;
    }

    $registered = nibwp_gblocks_registered();
    $v1 = nibwp_gblocks_v1_names();

    $with_schema = 0;
    $v1_registered = 0;

    foreach ($registered as $name => $type) {
        if (nibwp_gblocks_attributes($type) !== []) {
            $with_schema++;
        }
        if (in_array($name, $v1, true)) {
            $v1_registered++;
        }
    }

    $libraries = nibwp_gblocks_saved_libraries();

    return [
        'plugin_version' => defined('GENERATEBLOCKS_VERSION') ? (string) constant('GENERATEBLOCKS_VERSION') : null,
        'block_types' => count($registered),
        'block_types_with_attribute_schema' => $with_schema,
        'v1_block_types_registered' => $v1_registered,
        'v1_blocks_offered_in_editor' => nibwp_gblocks_v1_enabled(),
        'pattern_libraries_configured' => count($libraries),
        'writes_post_content' => false,
        'read_only_reason' => nibwp_gblocks_why_read_only(),
        'not_in_this_version' => [
            __('No custom post type of any kind, so no local patterns and no reusable style objects.', 'nibwp'),
            __('No store for global class definitions. Class names are readable from content; what they mean is not.', 'nibwp'),
            __('Pattern libraries are remote addresses. Nothing here fetches from them.', 'nibwp'),
        ],
    ];
}

/**
 * Every block type, with whether its attributes are knowable from here.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gblocks_list_block_types(): array|WP_Error
{
    if ($denied = nibwp_gblocks_require(NIBWP_GBLOCKS_READ_CAP, __('Listing the GenerateBlocks block types', 'nibwp'))) {
        return $denied;
    }

    $registered = nibwp_gblocks_registered();

    if ($registered === []) {
        return nibwp_gblocks_err(
            'no_registry',
            __('No GenerateBlocks block types are in the server registry. They are registered on init, so a request that runs earlier than that will see none.', 'nibwp'),
            500
        );
    }

    $rows = [];

    foreach ($registered as $name => $type) {
        $rows[] = nibwp_gblocks_type_row($name, $type);
    }

    return [
        'count' => count($rows),
        'block_types' => $rows,
        'note' => __('Call get_block_type for one block\'s full attribute schema. A block whose has_attribute_schema is false registered none on the server — its attributes are declared in JavaScript and are not knowable from here.', 'nibwp'),
    ];
}

/**
 * One block type with its attribute schema, straight out of the registry.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gblocks_get_block_type(array $input): array|WP_Error
{
    if ($denied = nibwp_gblocks_require(NIBWP_GBLOCKS_READ_CAP, __('Reading a GenerateBlocks block type', 'nibwp'))) {
        return $denied;
    }

    $name = nibwp_gblocks_block_name($input['block'] ?? null);
    if ($name instanceof WP_Error) {
        return $name;
    }

    $registered = nibwp_gblocks_registered();
    $type = $registered[$name];
    $attributes = nibwp_gblocks_attributes($type);

    $rows = [];

    foreach ($attributes as $attribute => $schema) {
        $rows[] = nibwp_gblocks_attribute_row((string) $attribute, $schema);
    }

    $sourced = [];

    foreach ($rows as $row) {
        if (isset($row['source'])) {
            $sourced[] = $row['name'];
        }
    }

    return nibwp_gblocks_type_row($name, $type) + [
        'description' => isset($type->description) && is_scalar($type->description) ? (string) $type->description : null,
        'attributes' => $rows,
        'sourced_from_markup' => $sourced,
        'supports' => isset($type->supports) && is_array($type->supports) ? $type->supports : null,
        'uses_context' => isset($type->uses_context) && is_array($type->uses_context) ? array_values($type->uses_context) : [],
        'provides_context' => isset($type->provides_context) && is_array($type->provides_context) ? $type->provides_context : [],
        'note' => $sourced === []
            ? null
            : __('The attributes in sourced_from_markup are not stored in the block comment — they are read back out of the saved HTML at the selector given. Setting one in the comment alone leaves the block invalid in the editor. This is the main reason this hub does not write content.', 'nibwp'),
    ];
}

/**
 * Every attribute name across every block, with which blocks carry it.
 *
 * The flat view, because the common mistake is using an attribute name from one
 * block on another that does not have it.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gblocks_list_block_attributes(): array|WP_Error
{
    if ($denied = nibwp_gblocks_require(NIBWP_GBLOCKS_READ_CAP, __('Listing the GenerateBlocks block attributes', 'nibwp'))) {
        return $denied;
    }

    $registered = nibwp_gblocks_registered();
    $index = [];
    $without = [];

    foreach ($registered as $name => $type) {
        $attributes = nibwp_gblocks_attributes($type);

        if ($attributes === []) {
            $without[] = $name;
            continue;
        }

        foreach ($attributes as $attribute => $schema) {
            $attribute = (string) $attribute;
            $schema = is_array($schema) ? $schema : [];

            if (!isset($index[$attribute])) {
                $index[$attribute] = [
                    'name' => $attribute,
                    'type' => isset($schema['type']) && is_scalar($schema['type']) ? (string) $schema['type'] : null,
                    'blocks' => [],
                ];
            }

            $index[$attribute]['blocks'][] = $name;
        }
    }

    ksort($index);

    return [
        'count' => count($index),
        'attributes' => array_values($index),
        'blocks_without_a_schema' => $without,
        'note' => __('An attribute is only valid on the blocks listed against it. The blocks in blocks_without_a_schema registered no attributes on the server, so none of their attribute names appear here at all.', 'nibwp'),
    ];
}

/**
 * The dynamic tags this install offers, and whether this account may author them.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gblocks_list_dynamic_tags(): array|WP_Error
{
    if ($denied = nibwp_gblocks_require(NIBWP_GBLOCKS_READ_CAP, __('Listing the GenerateBlocks dynamic tags', 'nibwp'))) {
        return $denied;
    }

    $registry = 'GenerateBlocks_Register_Dynamic_Tag';

    if (!class_exists($registry) || !method_exists($registry, 'get_tags')) {
        return nibwp_gblocks_err(
            'no_dynamic_tags',
            __('This version of GenerateBlocks does not expose its dynamic tags where this integration can read them.', 'nibwp'),
            500
        );
    }

    $tags = (array) $registry::get_tags();
    $rows = [];

    foreach ($tags as $tag => $data) {
        $data = is_array($data) ? $data : [];

        // 'return' is the callback that resolves the tag. The plugin drops it
        // before handing tags to its own editor script (includes/general.php:274)
        // and so does this: a closure is not data, and what it closes over is
        // nobody's business out here.
        $rows[] = [
            'tag' => (string) $tag,
            'title' => isset($data['title']) && is_scalar($data['title']) ? (string) $data['title'] : '',
            'type' => isset($data['type']) && is_scalar($data['type']) ? (string) $data['type'] : 'post',
        ];
    }

    $security = 'GenerateBlocks_Dynamic_Tag_Security';
    $can_author = null;

    if (class_exists($security) && method_exists($security, 'user_can_author_dynamic_data')) {
        $can_author = (bool) $security::user_can_author_dynamic_data();
    }

    return [
        'count' => count($rows),
        'dynamic_tags' => $rows,
        'current_account_may_author_dynamic_data' => $can_author,
        'note' => __('A dynamic tag resolves at render time and can read meta and options, so the plugin gates authoring them behind a capability of its own. Content containing one is refused on save for an account without it, whatever wrote the content.', 'nibwp'),
    ];
}

/* ----------------------------------------------------------------- the content */

/**
 * Which posts and pages are built with a given block.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gblocks_find_posts_using_block(array $input): array|WP_Error
{
    if ($denied = nibwp_gblocks_require(NIBWP_GBLOCKS_READ_CAP, __('Finding the posts that use a GenerateBlocks block', 'nibwp'))) {
        return $denied;
    }

    $name = nibwp_gblocks_block_name($input['block'] ?? null);
    if ($name instanceof WP_Error) {
        return $name;
    }

    // The prefilter is the name without a trailing delimiter, so it also matches
    // generateblocks/button-container when asked about generateblocks/button. The
    // parse below throws those away — which is the whole reason the parse is here.
    $found = nibwp_gblocks_candidates($input, '<!-- wp:' . $name);
    if ($found instanceof WP_Error) {
        return $found;
    }

    $rows = [];

    foreach ($found['ids'] as $id) {
        $counts = nibwp_gblocks_count_blocks(nibwp_gblocks_content($id));

        if (($counts[$name] ?? 0) < 1) {
            continue;
        }

        $rows[] = nibwp_gblocks_post_row($id) + ['uses' => (int) $counts[$name]];
    }

    return [
        'block' => $name,
        'returned' => count($rows),
        'posts' => $rows,
        'candidates_examined' => $found['candidates'],
        'skipped_no_permission' => $found['skipped_no_permission'],
        'truncated' => $found['truncated'],
        'note' => nibwp_gblocks_scan_note($found),
    ];
}

/**
 * How much of the site is on each block, v1 generation included.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gblocks_block_usage_report(array $input): array|WP_Error
{
    if ($denied = nibwp_gblocks_require(NIBWP_GBLOCKS_READ_CAP, __('Reading the GenerateBlocks usage report', 'nibwp'))) {
        return $denied;
    }

    $found = nibwp_gblocks_candidates($input, '<!-- wp:' . NIBWP_GBLOCKS_NS);
    if ($found instanceof WP_Error) {
        return $found;
    }

    $v1 = nibwp_gblocks_v1_names();
    $registered = nibwp_gblocks_registered();

    $totals = [];
    $posts_with = [];
    $v1_posts = [];

    foreach ($found['ids'] as $id) {
        $counts = nibwp_gblocks_count_blocks(nibwp_gblocks_content($id));
        $has_v1 = false;

        foreach ($counts as $name => $count) {
            $totals[$name] = ($totals[$name] ?? 0) + $count;
            $posts_with[$name] = ($posts_with[$name] ?? 0) + 1;

            if (in_array($name, $v1, true)) {
                $has_v1 = true;
            }
        }

        if ($has_v1) {
            $v1_posts[] = $id;
        }
    }

    arsort($totals);

    $rows = [];

    foreach ($totals as $name => $count) {
        $rows[] = [
            'block' => $name,
            'generation' => in_array($name, $v1, true) ? 'v1' : 'v2',
            'registered' => isset($registered[$name]),
            'instances' => $count,
            'posts' => (int) ($posts_with[$name] ?? 0),
        ];
    }

    return [
        'posts_examined' => count($found['ids']),
        'candidates_examined' => $found['candidates'],
        'skipped_no_permission' => $found['skipped_no_permission'],
        'truncated' => $found['truncated'],
        'blocks' => $rows,
        'posts_using_v1_blocks' => count($v1_posts),
        'note' => nibwp_gblocks_scan_note($found),
    ];
}

/**
 * The block tree of one post, as names, ids and the attributes actually present.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gblocks_get_post_blocks(array $input): array|WP_Error
{
    if ($denied = nibwp_gblocks_require(NIBWP_GBLOCKS_READ_CAP, __('Reading a post\'s GenerateBlocks blocks', 'nibwp'))) {
        return $denied;
    }

    $post_id = isset($input['post_id']) ? (int) $input['post_id'] : 0;

    // Before the lookup, so a post this account may not edit and a post that is
    // not there refuse with the same code and the same message.
    if ($denied = nibwp_gblocks_require_post($post_id, __('Reading a post\'s GenerateBlocks blocks', 'nibwp'))) {
        return $denied;
    }

    $post = get_post($post_id);

    if (!is_object($post)) {
        return nibwp_gblocks_err('no_post', __('There is no post with that id.', 'nibwp'), 404);
    }

    $content = nibwp_gblocks_content($post_id);
    $registered = nibwp_gblocks_registered();
    $rows = [];

    nibwp_gblocks_walk(
        nibwp_gblocks_parse($content),
        function (array $block, int $depth) use (&$rows, $registered): void {
            $name = isset($block['blockName']) && is_string($block['blockName']) ? $block['blockName'] : '';

            if ($name === '' || !str_starts_with($name, NIBWP_GBLOCKS_NS)) {
                return;
            }

            $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
            $schema = isset($registered[$name]) ? nibwp_gblocks_attributes($registered[$name]) : [];

            $rows[] = [
                'block' => $name,
                'depth' => $depth,
                'registered' => isset($registered[$name]),
                'unique_id' => isset($attrs['uniqueId']) && is_scalar($attrs['uniqueId']) ? (string) $attrs['uniqueId'] : null,
                'tag_name' => isset($attrs['tagName']) && is_scalar($attrs['tagName']) ? (string) $attrs['tagName'] : null,
                // Keys, not values: a styles object is hundreds of declarations
                // and nobody asked for the whole page's CSS in a listing.
                'attributes_present' => array_values(array_map('strval', array_keys($attrs))),
                'attributes_unexpected' => nibwp_gblocks_unexpected($attrs, $schema),
                'schema_known' => $schema !== [],
                'global_classes' => isset($attrs['globalClasses']) && is_array($attrs['globalClasses'])
                    ? array_values(array_filter($attrs['globalClasses'], 'is_scalar'))
                    : [],
            ];
        }
    );

    return [
        'post_id' => $post_id,
        'post_type' => isset($post->post_type) && is_scalar($post->post_type) ? (string) $post->post_type : null,
        'post_title' => isset($post->post_title) && is_scalar($post->post_title) ? (string) $post->post_title : '',
        'count' => count($rows),
        'blocks' => $rows,
        'writes_post_content' => false,
        'note' => __('Attribute names only, not their values: a styles attribute holds a whole block\'s CSS. Anything in attributes_unexpected is not in that block\'s registered schema, which usually means markup written by hand or by an older version.', 'nibwp'),
    ];
}

/**
 * Which global class names content refers to.
 *
 * Usage, not definitions. This version of the plugin stores no definitions —
 * see the file header.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gblocks_list_global_classes(array $input): array|WP_Error
{
    if ($denied = nibwp_gblocks_require(NIBWP_GBLOCKS_READ_CAP, __('Listing the global classes GenerateBlocks content uses', 'nibwp'))) {
        return $denied;
    }

    $found = nibwp_gblocks_candidates($input, '<!-- wp:' . NIBWP_GBLOCKS_NS);
    if ($found instanceof WP_Error) {
        return $found;
    }

    $uses = [];

    foreach ($found['ids'] as $id) {
        nibwp_gblocks_walk(
            nibwp_gblocks_parse(nibwp_gblocks_content($id)),
            function (array $block) use (&$uses, $id): void {
                $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
                $classes = isset($attrs['globalClasses']) && is_array($attrs['globalClasses']) ? $attrs['globalClasses'] : [];

                foreach ($classes as $class) {
                    if (!is_scalar($class)) {
                        continue;
                    }

                    $class = (string) $class;

                    if ($class === '') {
                        continue;
                    }

                    if (!isset($uses[$class])) {
                        $uses[$class] = ['class' => $class, 'instances' => 0, 'post_ids' => []];
                    }

                    $uses[$class]['instances']++;

                    if (!in_array($id, $uses[$class]['post_ids'], true)) {
                        $uses[$class]['post_ids'][] = $id;
                    }
                }
            }
        );
    }

    ksort($uses);

    $rows = [];

    foreach ($uses as $row) {
        $row['posts'] = count($row['post_ids']);
        $rows[] = $row;
    }

    return [
        'count' => count($rows),
        'global_classes' => $rows,
        'posts_examined' => count($found['ids']),
        'skipped_no_permission' => $found['skipped_no_permission'],
        'truncated' => $found['truncated'],
        'definitions_available' => false,
        'note' => __('These are the class names blocks refer to. This version of GenerateBlocks stores no definitions behind them — the styles that give a global class meaning come from a separate product that is not installed here, so a name appearing with no styling is expected rather than broken.', 'nibwp'),
    ];
}

/**
 * Posts whose block markup will not open cleanly in the editor.
 *
 * Three findings, each from a different signal, because they fail differently:
 * a delimiter the parser could not read at all; an attribute payload that is not
 * JSON, which the core parser turns silently into nothing; and a block name
 * nothing registers, which the editor shows as unsupported content.
 *
 * Plus the attributes that are not in the block's registered schema, which is
 * the quiet one: the front end ignores them and the editor drops them the next
 * time somebody saves, so whatever they were meant to do stops happening with no
 * error anywhere.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gblocks_audit_invalid_markup(array $input): array|WP_Error
{
    if ($denied = nibwp_gblocks_require(NIBWP_GBLOCKS_READ_CAP, __('Auditing GenerateBlocks block markup', 'nibwp'))) {
        return $denied;
    }

    $found = nibwp_gblocks_candidates($input, '<!-- wp:' . NIBWP_GBLOCKS_NS);
    if ($found instanceof WP_Error) {
        return $found;
    }

    $registered = nibwp_gblocks_registered();
    $rows = [];

    foreach ($found['ids'] as $id) {
        $content = nibwp_gblocks_content($id);
        $problems = [];

        // 1. A delimiter the parser could not read ends up inside a freeform
        //    chunk. That chunk will render as literal text.
        nibwp_gblocks_walk(
            nibwp_gblocks_parse($content),
            function (array $block) use (&$problems, $registered): void {
                $name = isset($block['blockName']) && is_string($block['blockName']) ? $block['blockName'] : '';
                $inner = isset($block['innerHTML']) && is_string($block['innerHTML']) ? $block['innerHTML'] : '';

                // innerHTML holds only the text the parser did NOT turn into a
                // block, so a delimiter surviving in there was one the parser
                // refused — malformed, whatever it looks like. Checked on every
                // node, named or not, because a broken delimiter inside a healthy
                // container never becomes a node of its own.
                if (nibwp_gblocks_stray_delimiter($inner)) {
                    $problems[] = [
                        'kind' => 'unparsed_delimiter',
                        'block' => $name === '' ? null : $name,
                        'detail' => __('A GenerateBlocks block delimiter survived in plain content, so the parser never read it as a block. It will show up as text on the page.', 'nibwp'),
                    ];
                }

                if ($name === '' || !str_starts_with($name, NIBWP_GBLOCKS_NS)) {
                    return;
                }

                // 2. An attribute payload that is not JSON. parse_blocks() turns
                //    it into null rather than failing
                //    (wp-includes/class-wp-block-parser.php:279-281).
                if (array_key_exists('attrs', $block) && !is_array($block['attrs'])) {
                    $problems[] = [
                        'kind' => 'broken_attributes',
                        'block' => $name,
                        'detail' => __('The attribute payload in the block comment is not valid JSON, so every attribute on this block was discarded.', 'nibwp'),
                    ];
                }

                // 3. A name nothing registers. The editor shows this as content
                //    it does not support and offers to convert it to HTML.
                if (!isset($registered[$name])) {
                    $problems[] = [
                        'kind' => 'unregistered_block',
                        'block' => $name,
                        'detail' => __('No block of this name is registered here, so the editor will treat it as unsupported content.', 'nibwp'),
                    ];

                    return;
                }

                $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
                $schema = nibwp_gblocks_attributes($registered[$name]);

                // Only when a schema exists. For the v1 blocks it does not, and
                // reporting all of their attributes as unexpected would bury the
                // real findings under a list of perfectly ordinary markup.
                if ($schema === []) {
                    return;
                }

                $unexpected = nibwp_gblocks_unexpected($attrs, $schema);

                if ($unexpected !== []) {
                    $problems[] = [
                        'kind' => 'unexpected_attributes',
                        'block' => $name,
                        'attributes' => $unexpected,
                        'detail' => __('These attribute names are not in this block\'s registered schema. The front end ignores them and the editor drops them on the next save.', 'nibwp'),
                    ];
                }
            }
        );

        // Read from the raw markup rather than the tree, because an unbalanced
        // closer never becomes a node to inspect.
        foreach (nibwp_gblocks_unbalanced($content) as $name => $delta) {
            $problems[] = [
                'kind' => 'unbalanced_delimiters',
                'block' => (string) $name,
                'detail' => $delta > 0
                    ? __('More opening delimiters than closing ones for this block, so the editor will read the rest of the page as being inside it.', 'nibwp')
                    : __('More closing delimiters than opening ones for this block.', 'nibwp'),
            ];
        }

        if ($problems !== []) {
            $rows[] = nibwp_gblocks_post_row($id) + [
                'problem_count' => count($problems),
                'problems' => $problems,
            ];
        }
    }

    return [
        'posts_examined' => count($found['ids']),
        'candidates_examined' => $found['candidates'],
        'skipped_no_permission' => $found['skipped_no_permission'],
        'truncated' => $found['truncated'],
        'returned' => count($rows),
        'posts' => $rows,
        'note' => __('These are the problems PHP can see. Real block validation happens in the editor\'s JavaScript against what each block\'s save() would have produced, so a post absent from this list is not proven clean — it is only free of the faults that are visible from the server. Nothing here is fixed automatically; this hub does not write content.', 'nibwp'),
    ];
}

/**
 * Blocks left with placeholder content.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gblocks_audit_placeholder_content(array $input): array|WP_Error
{
    if ($denied = nibwp_gblocks_require(NIBWP_GBLOCKS_READ_CAP, __('Auditing GenerateBlocks placeholder content', 'nibwp'))) {
        return $denied;
    }

    $found = nibwp_gblocks_candidates($input, '<!-- wp:' . NIBWP_GBLOCKS_NS);
    if ($found instanceof WP_Error) {
        return $found;
    }

    $rows = [];

    foreach ($found['ids'] as $id) {
        $findings = [];

        nibwp_gblocks_walk(
            nibwp_gblocks_parse(nibwp_gblocks_content($id)),
            function (array $block) use (&$findings): void {
                $name = isset($block['blockName']) && is_string($block['blockName']) ? $block['blockName'] : '';

                if ($name === '' || !str_starts_with($name, NIBWP_GBLOCKS_NS)) {
                    return;
                }

                $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
                $inner = isset($block['innerHTML']) && is_string($block['innerHTML']) ? $block['innerHTML'] : '';
                $html = (array) ($attrs['htmlAttributes'] ?? []);

                // An image with no attachment behind it. mediaId defaults to 0
                // (dist/blocks/media/block.json:43), and the plugin's own
                // placeholder files are what the editor shows meanwhile
                // (includes/general.php:113-116).
                if ($name === 'generateblocks/media' || $name === 'generateblocks/image') {
                    $src = isset($html['src']) && is_scalar($html['src']) ? (string) $html['src'] : '';
                    $media_id = isset($attrs['mediaId']) ? (int) $attrs['mediaId'] : 0;

                    if ($media_id < 1 && ($src === '' || str_contains($src, 'image-placeholder.png'))) {
                        $findings[] = [
                            'kind' => 'image_without_media',
                            'block' => $name,
                            'detail' => __('No attachment is set on this image and its source is empty or the plugin\'s placeholder file.', 'nibwp'),
                        ];
                    }
                }

                // Text with nothing in it. content is sourced from .gb-text
                // (dist/blocks/text/block.json:38-43), so an empty innerHTML with
                // no content attribute is a block somebody inserted and left.
                if ($name === 'generateblocks/text' || $name === 'generateblocks/headline') {
                    $text = isset($attrs['content']) && is_scalar($attrs['content']) ? (string) $attrs['content'] : '';

                    if (trim($text) === '' && trim(wp_strip_all_tags($inner)) === '') {
                        $findings[] = [
                            'kind' => 'empty_text',
                            'block' => $name,
                            'detail' => __('This text block has no content, in the attribute or in the markup.', 'nibwp'),
                        ];
                    }
                }

                // A link that goes nowhere, which is the other half of a
                // half-finished section.
                $href = isset($html['href']) && is_scalar($html['href']) ? trim((string) $html['href']) : '';

                if ($href === '#' || $href === 'https://' || $href === 'http://') {
                    $findings[] = [
                        'kind' => 'placeholder_link',
                        'block' => $name,
                        'detail' => __('The link on this block is still a placeholder target.', 'nibwp'),
                    ];
                }

                // Lorem ipsum, in either the attribute or the saved markup.
                $haystack = strtolower($inner . ' ' . (isset($attrs['content']) && is_scalar($attrs['content']) ? (string) $attrs['content'] : ''));

                if (str_contains($haystack, 'lorem ipsum')) {
                    $findings[] = [
                        'kind' => 'lorem_ipsum',
                        'block' => $name,
                        'detail' => __('Placeholder copy is still in this block.', 'nibwp'),
                    ];
                }
            }
        );

        if ($findings !== []) {
            $rows[] = nibwp_gblocks_post_row($id) + [
                'finding_count' => count($findings),
                'findings' => $findings,
            ];
        }
    }

    return [
        'posts_examined' => count($found['ids']),
        'candidates_examined' => $found['candidates'],
        'skipped_no_permission' => $found['skipped_no_permission'],
        'truncated' => $found['truncated'],
        'returned' => count($rows),
        'posts' => $rows,
        'note' => __('A deliberately empty block is indistinguishable from a forgotten one, so read this as a list to look at rather than a list of faults. Nothing here is changed automatically.', 'nibwp'),
    ];
}

/**
 * Attribute names on a block that its registered schema does not have.
 *
 * @param array<string, mixed> $attrs
 * @param array<string, mixed> $schema
 * @return array<int, string>
 */
function nibwp_gblocks_unexpected(array $attrs, array $schema): array
{
    if ($schema === []) {
        return [];
    }

    $tolerated = nibwp_gblocks_tolerated_attributes();
    $unexpected = [];

    foreach (array_keys($attrs) as $key) {
        $key = (string) $key;

        if (array_key_exists($key, $schema) || in_array($key, $tolerated, true)) {
            continue;
        }

        $unexpected[] = $key;
    }

    return $unexpected;
}

/**
 * Does this stretch of plain content still hold a block delimiter?
 *
 * Matched as an HTML-comment opener followed by the namespace, rather than the
 * namespace on its own, so prose that happens to mention a block name is not a
 * finding. A well-formed delimiter can never reach here: the parser would have
 * consumed it and it would not be in innerHTML at all.
 */
function nibwp_gblocks_stray_delimiter(string $html): bool
{
    if ($html === '') {
        return false;
    }

    return preg_match('#<!-*\s*/?\s*wp:' . preg_quote(NIBWP_GBLOCKS_NS, '#') . '#', $html) === 1;
}

/**
 * Per block name, openers minus closers, for the names where they disagree.
 *
 * A void delimiter is both at once and cancels out.
 *
 * @return array<string, int>
 */
function nibwp_gblocks_unbalanced(string $content): array
{
    $balance = [];

    foreach (nibwp_gblocks_delimiters($content) as $delimiter) {
        $name = (string) $delimiter['name'];

        if (!isset($balance[$name])) {
            $balance[$name] = 0;
        }

        if ($delimiter['is_void']) {
            continue;
        }

        $balance[$name] += $delimiter['is_closer'] ? -1 : 1;
    }

    return array_filter($balance, fn (int $delta): bool => $delta !== 0);
}

/**
 * How a post is identified in a listing.
 *
 * @return array<string, mixed>
 */
function nibwp_gblocks_post_row(int $post_id): array
{
    $post = get_post($post_id);

    return [
        'post_id' => $post_id,
        'post_type' => is_object($post) && isset($post->post_type) && is_scalar($post->post_type) ? (string) $post->post_type : null,
        'post_status' => is_object($post) && isset($post->post_status) && is_scalar($post->post_status) ? (string) $post->post_status : null,
        'post_title' => is_object($post) && isset($post->post_title) && is_scalar($post->post_title) ? (string) $post->post_title : '',
    ];
}

/**
 * What a caller should know about how complete a scan was.
 *
 * @param array<string, mixed> $found
 */
function nibwp_gblocks_scan_note(array $found): string
{
    $skipped = (int) ($found['skipped_no_permission'] ?? 0);

    if ($skipped > 0) {
        return sprintf(
            /* translators: %d: number of posts dropped from the result */
            __('%d post was left out because this account cannot edit it, so this is a partial picture. Say so rather than reporting it as the whole site.', 'nibwp'),
            $skipped
        );
    }

    if (($found['truncated'] ?? false) === true) {
        return __('There are more posts than this page covers. Raise limit or page through with offset before concluding anything about totals.', 'nibwp');
    }

    return __('Every post in range was examined.', 'nibwp');
}

/* -------------------------------------------------------------- plugin-wide */

/** Whether the editor is offering the deprecated v1 blocks. */
function nibwp_gblocks_v1_enabled(): bool
{
    if (function_exists('generateblocks_use_v1_blocks')) {
        try {
            return (bool) generateblocks_use_v1_blocks();
        } catch (\Throwable $error) {
            // Fall through to the raw option.
        }
    }

    return (bool) get_option('gb_use_v1_blocks', false);
}

/**
 * The plugin's own defaults for the settings option.
 *
 * Read through the plugin's filterable helper (includes/defaults.php:61) rather
 * than copied, so a site that has filtered them sees its own values and an
 * update that adds a key is followed without an edit here.
 *
 * @return array<string, mixed>
 */
function nibwp_gblocks_setting_defaults(): array
{
    if (function_exists('generateblocks_get_option_defaults')) {
        try {
            $defaults = generateblocks_get_option_defaults();

            if (is_array($defaults) && $defaults !== []) {
                return $defaults;
            }
        } catch (\Throwable $error) {
            // Fall through to the literal below.
        }
    }

    return [
        'container_width' => 1100,
        'css_print_method' => 'file',
        'sync_responsive_previews' => true,
        'disable_google_fonts' => true,
    ];
}

/**
 * The settings, with what is saved and what is the default, key by key.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gblocks_get_settings(): array|WP_Error
{
    if ($denied = nibwp_gblocks_require(NIBWP_GBLOCKS_SETTINGS_CAP, __('Reading the GenerateBlocks settings', 'nibwp'))) {
        return $denied;
    }

    $saved = get_option('generateblocks', []);
    $saved = is_array($saved) ? $saved : [];
    $rows = [];

    foreach (nibwp_gblocks_setting_defaults() as $key => $default) {
        $key = (string) $key;
        $rows[] = [
            'key' => $key,
            'value' => array_key_exists($key, $saved) ? $saved[$key] : $default,
            'default' => $default,
            'is_customised' => array_key_exists($key, $saved) && $saved[$key] !== $default,
            'writable' => true,
        ];
    }

    // Kept out of the option above by the plugin itself: it is written as a
    // standalone option (includes/class-rest.php:171-181) and read as one
    // (includes/functions.php:2059).
    $rows[] = [
        'key' => 'gb_use_v1_blocks',
        'value' => nibwp_gblocks_v1_enabled(),
        'default' => false,
        'is_customised' => get_option('gb_use_v1_blocks', null) !== null,
        'writable' => true,
        'note' => __('A standalone option, not part of the settings object. Change it with set_v1_blocks_available, which checks whether any content still uses those blocks first.', 'nibwp'),
    ];

    return [
        'count' => count($rows),
        'settings' => $rows,
        'note' => __('css_print_method is file or inline. container_width is a pixel number. The other two are switches. Unknown keys are refused by update_settings rather than stored, which matches the plugin: it drops a key it does not recognise.', 'nibwp'),
    ];
}

/**
 * Change the settings the plugin itself allows to be changed.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gblocks_update_settings(array $input): array|WP_Error
{
    if ($denied = nibwp_gblocks_require(NIBWP_GBLOCKS_SETTINGS_CAP, __('Changing the GenerateBlocks settings', 'nibwp'))) {
        return $denied;
    }

    $patch = isset($input['settings']) && is_array($input['settings']) ? $input['settings'] : null;

    if ($patch === null || $patch === []) {
        return nibwp_gblocks_err('missing_settings', __('Give the keys to change as settings, an object.', 'nibwp'), 422);
    }

    $defaults = nibwp_gblocks_setting_defaults();
    $saved = get_option('generateblocks', []);
    $saved = is_array($saved) ? $saved : [];

    $changes = [];

    foreach ($patch as $key => $value) {
        $key = (string) $key;

        // The plugin silently drops a key it does not know (class-rest.php:205).
        // Refusing instead: an agent that misspells a key and is told nothing
        // reports the change as made, and the owner believes it.
        if (!array_key_exists($key, $defaults)) {
            return nibwp_gblocks_err(
                'bad_setting',
                sprintf(
                    /* translators: 1: the rejected key, 2: list of valid keys */
                    __('"%1$s" is not a GenerateBlocks setting. Valid keys: %2$s.', 'nibwp'),
                    $key,
                    implode(', ', array_keys($defaults))
                ),
                422
            );
        }

        $clean = nibwp_gblocks_sanitize_setting($key, $value);

        if ($clean instanceof WP_Error) {
            return $clean;
        }

        $changes[$key] = $clean;
    }

    update_option('generateblocks', array_merge($saved, $changes));

    return [
        'updated' => array_keys($changes),
        'settings' => $changes,
        'css_cache_cleared' => false,
        'note' => __('Saved. Container width and print method change the CSS the plugin generates, and the generated files are not rebuilt by this — call regenerate_css if a change does not show up on the front end.', 'nibwp'),
    ];
}

/**
 * One setting, validated the way the plugin validates it, and then some.
 *
 * The plugin's own sanitisers (includes/class-rest.php:141-160) are absint and
 * sanitize_text_field, which accept a container width of zero and a print method
 * of "banana" without complaint. Both are checked properly here, because a bad
 * value in this option shows up as a site with no layout.
 *
 * @param mixed $value
 * @return mixed|WP_Error
 */
function nibwp_gblocks_sanitize_setting(string $key, $value)
{
    if ($key === 'container_width') {
        if (!is_numeric($value)) {
            return nibwp_gblocks_err('bad_setting_value', __('container_width must be a number of pixels.', 'nibwp'), 422);
        }

        $width = (int) $value;

        if ($width < 100 || $width > 5000) {
            return nibwp_gblocks_err('bad_setting_value', __('container_width must be between 100 and 5000 pixels.', 'nibwp'), 422);
        }

        return $width;
    }

    if ($key === 'css_print_method') {
        $method = is_scalar($value) ? strtolower(trim((string) $value)) : '';

        // The two the plugin acts on (includes/class-enqueue-css.php:110-135).
        if (!in_array($method, ['file', 'inline'], true)) {
            return nibwp_gblocks_err('bad_setting_value', __('css_print_method must be file or inline.', 'nibwp'), 422);
        }

        return $method;
    }

    if (is_bool($value)) {
        return $value;
    }

    if (is_scalar($value)) {
        $text = strtolower(trim((string) $value));

        if (in_array($text, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($text, ['0', 'false', 'no', 'off', ''], true)) {
            return false;
        }
    }

    return nibwp_gblocks_err(
        'bad_setting_value',
        sprintf(
            /* translators: %s: the setting key */
            __('%s is a switch; give it true or false.', 'nibwp'),
            $key
        ),
        422
    );
}

/**
 * The plugin's per-block defaults, for the v1 blocks that have them.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gblocks_get_block_defaults(): array|WP_Error
{
    if ($denied = nibwp_gblocks_require(NIBWP_GBLOCKS_SETTINGS_CAP, __('Reading the GenerateBlocks block defaults', 'nibwp'))) {
        return $denied;
    }

    if (!function_exists('generateblocks_get_block_defaults')) {
        return nibwp_gblocks_err(
            'no_block_defaults',
            __('This version of GenerateBlocks does not expose its block defaults where this integration can read them.', 'nibwp'),
            500
        );
    }

    $defaults = (array) generateblocks_get_block_defaults();
    $rows = [];

    foreach ($defaults as $block => $values) {
        $values = is_array($values) ? $values : [];
        $set = [];

        foreach ($values as $key => $value) {
            if (is_scalar($value) && $value !== '' && $value !== false) {
                $set[(string) $key] = $value;
            }
        }

        $rows[] = [
            'block' => (string) $block,
            'keys' => count($values),
            'non_empty' => $set,
        ];
    }

    return [
        'count' => count($rows),
        'block_defaults' => $rows,
        'writable' => false,
        'note' => __('These cover the v1 blocks only, and this version builds them from filters rather than storing them, so there is nothing to write back. A site that wants different defaults sets them with the plugin\'s generateblocks_defaults filter in code.', 'nibwp'),
    ];
}

/**
 * The state of the generated-CSS cache.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gblocks_get_css_status(): array|WP_Error
{
    if ($denied = nibwp_gblocks_require(NIBWP_GBLOCKS_SETTINGS_CAP, __('Reading the GenerateBlocks CSS status', 'nibwp'))) {
        return $denied;
    }

    $posts = get_option('generateblocks_dynamic_css_posts', []);
    $posts = is_array($posts) ? $posts : [];
    $time = get_option('generateblocks_dynamic_css_time', 0);

    $saved = get_option('generateblocks', []);
    $saved = is_array($saved) ? $saved : [];
    $method = isset($saved['css_print_method']) && is_scalar($saved['css_print_method'])
        ? (string) $saved['css_print_method']
        : (string) (nibwp_gblocks_setting_defaults()['css_print_method'] ?? 'file');

    return [
        'print_method_setting' => $method,
        'cached_posts' => count($posts),
        'last_write' => is_numeric($time) && (int) $time > 0 ? gmdate('Y-m-d H:i:s', (int) $time) : null,
        'note' => __('The setting is a preference, not a guarantee: the plugin falls back to inline CSS on previews, on single posts, and whenever it cannot write its file. An empty cache is normal straight after a plugin update, which clears it.', 'nibwp'),
    ];
}

/**
 * Empty the generated-CSS cache so it rebuilds.
 *
 * The same single write the plugin's own button makes
 * (includes/class-rest.php:232-236). It deletes no content and loses nothing
 * that cannot be regenerated from the posts themselves.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gblocks_regenerate_css(): array|WP_Error
{
    if ($denied = nibwp_gblocks_require(NIBWP_GBLOCKS_SETTINGS_CAP, __('Regenerating the GenerateBlocks CSS', 'nibwp'))) {
        return $denied;
    }

    $before = get_option('generateblocks_dynamic_css_posts', []);
    $before = is_array($before) ? $before : [];

    update_option('generateblocks_dynamic_css_posts', []);

    return [
        'cleared' => count($before),
        'note' => __('The cache is empty. Each page rebuilds its CSS the next time somebody loads it, so nothing happens until then and the first load of a large page is slower.', 'nibwp'),
    ];
}

/**
 * The remote pattern libraries this site is configured against.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_gblocks_saved_libraries(): array
{
    $saved = get_option('generateblocks_pattern_libraries', []);

    if (!is_array($saved)) {
        return [];
    }

    $rows = [];

    foreach ($saved as $library) {
        if (!is_array($library)) {
            continue;
        }

        $rows[] = [
            'id' => isset($library['id']) && is_scalar($library['id']) ? (string) $library['id'] : null,
            'name' => isset($library['name']) && is_scalar($library['name']) ? (string) $library['name'] : '',
            'domain' => isset($library['domain']) && is_scalar($library['domain']) ? (string) $library['domain'] : '',
            'is_enabled' => isset($library['isEnabled']) ? (bool) $library['isEnabled'] : null,
            'is_local' => isset($library['isLocal']) ? (bool) $library['isLocal'] : null,
            // The key authenticates this site to that library. It is never
            // returned; whether one is configured is all anybody needs here.
            'public_key_set' => isset($library['publicKey']) && $library['publicKey'] !== '',
            'public_key_returned' => false,
        ];
    }

    return $rows;
}

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gblocks_list_pattern_libraries(): array|WP_Error
{
    if ($denied = nibwp_gblocks_require(NIBWP_GBLOCKS_SETTINGS_CAP, __('Listing the GenerateBlocks pattern libraries', 'nibwp'))) {
        return $denied;
    }

    $rows = nibwp_gblocks_saved_libraries();

    return [
        'count' => count($rows),
        'libraries' => $rows,
        'patterns_available_here' => false,
        'note' => __('Configuration only. The patterns themselves live on those domains and are fetched over HTTP by the editor; nothing here makes an outbound request, and this version of the plugin stores no patterns of its own. The plugin\'s built-in default library is not in this list because it is not stored as configuration.', 'nibwp'),
    ];
}

/**
 * Turn the deprecated v1 blocks on or off in the editor.
 *
 * Turning them OFF is refused while any post still contains one. The plugin
 * registers them on the server either way, so the front end keeps rendering —
 * but the editor stops recognising them, and a page an owner can still see is a
 * page they can no longer edit. That is a trap worth refusing rather than
 * warning about.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_gblocks_set_v1_blocks_available(array $input): array|WP_Error
{
    if ($denied = nibwp_gblocks_require(NIBWP_GBLOCKS_SETTINGS_CAP, __('Changing whether the v1 blocks are offered', 'nibwp'))) {
        return $denied;
    }

    if (!array_key_exists('enabled', $input) || !is_bool($input['enabled'])) {
        return nibwp_gblocks_err('missing_enabled', __('Give enabled as true or false.', 'nibwp'), 422);
    }

    $enabled = $input['enabled'];
    $was = nibwp_gblocks_v1_enabled();

    if (!$enabled) {
        $in_use = nibwp_gblocks_count_v1_posts();

        if ($in_use instanceof WP_Error) {
            return $in_use;
        }

        if ($in_use > 0) {
            return nibwp_gblocks_err(
                'v1_in_use',
                sprintf(
                    /* translators: %d: number of posts still using a v1 block */
                    __('%d post still contains a v1 block. Turning them off would leave the editor unable to open those posts, so this refuses. Convert them first.', 'nibwp'),
                    $in_use
                ),
                409
            );
        }
    }

    update_option('gb_use_v1_blocks', $enabled);

    return [
        'enabled' => $enabled,
        'was' => $was,
        'changed' => $was !== $enabled,
        'note' => $enabled
            ? __('The v1 blocks are offered in the editor again. They are deprecated, so this is a way back to an existing page rather than a way to build a new one.', 'nibwp')
            : __('The v1 blocks are no longer offered in the editor. The server still registers them, so anything published keeps rendering.', 'nibwp'),
    ];
}

/**
 * How many posts contain a v1 block, counted across the whole site.
 *
 * Not the bounded scan the listings use: this number decides whether a write is
 * allowed, and a count that stopped at two hundred rows could answer "none" for
 * a site with thousands. The match is deliberately loose — the prefix for
 * generateblocks/button also matches generateblocks/button-container — because
 * every name it over-matches is itself a v1 block, and over-counting here
 * refuses a safe change while under-counting breaks pages.
 *
 * @return int|WP_Error
 */
function nibwp_gblocks_count_v1_posts(): int|WP_Error
{
    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
        return nibwp_gblocks_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    $clauses = [];
    $args = [];

    foreach (nibwp_gblocks_v1_names() as $name) {
        $clauses[] = 'post_content LIKE %s';
        $args[] = '%' . $wpdb->esc_like('<!-- wp:' . $name) . '%';
    }

    if ($clauses === []) {
        return 0;
    }

    $sql = 'SELECT COUNT(*) FROM ' . $wpdb->posts
        . " WHERE post_type <> 'revision'"
        . " AND post_status NOT IN ('trash', 'auto-draft')"
        . ' AND (' . implode(' OR ', $clauses) . ')';

    return (int) $wpdb->get_var($wpdb->prepare($sql, $args));
}
