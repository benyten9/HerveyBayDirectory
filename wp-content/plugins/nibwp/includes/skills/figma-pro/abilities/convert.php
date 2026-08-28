<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * figma-pro abilities — the MCP tool surface for Figma → WordPress.
 *
 * These make the skill real and callable by an AI agent over NibWP's MCP: an
 * agent that also reads a design (Figma Dev Mode MCP, or a pasted link) can call
 * these to build it natively in WordPress. They reuse the same client → NDO →
 * builder pipeline as the admin converter (includes/integrations/figma/).
 *
 * Loaded from the Figma integration bootstrap AND the skill registry; the define
 * guard below makes double-loading a no-op.
 */

if (defined('NIBWP_FIGMA_PRO_ABILITIES_LOADED')) {
    return;
}
define('NIBWP_FIGMA_PRO_ABILITIES_LOADED', true);

/** Shared gate: skill entitlement first (same as every Pro skill), then a live Figma connection. */
function nibwp_figma_pro_gate()
{
    if (function_exists('nibwp_skill_gate')) {
        $gate = nibwp_skill_gate('figma-pro');
        if (is_wp_error($gate)) {
            return $gate;
        }
    }
    if (!function_exists('nibwp_figma_is_connected') || !nibwp_figma_is_connected()) {
        return new WP_Error(
            'figma_not_connected',
            'Figma is not connected. Connect a token or OAuth at wp-admin → NIBWP → Figma.',
            ['status' => 409, 'connect_url' => admin_url('admin.php?page=nibwp-figma')]
        );
    }
    return true;
}

/* ── detect-builder ──────────────────────────────────────────────────────── */
wp_register_ability('nibwp/figma-pro-detect-builder', [
    'label'       => __('Figma Pro — detect builder', 'nibwp'),
    'description' => __('Report the WordPress page builders active on this site, which emitters are implemented, the active enhancer skills (acss-pro, seo-pro), and whether Figma is connected. Call first to decide the convert target.', 'nibwp'),
    'category'    => 'content',
    'input_schema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
    'execute_callback'    => 'nibwp_figma_pro_ability_detect',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function nibwp_figma_pro_ability_detect(array $input): array|WP_Error
{
    $out = nibwp_figma_detect_builders();
    $out['figma_connected'] = function_exists('nibwp_figma_is_connected') && nibwp_figma_is_connected();
    $out['note'] = 'Only the Gutenberg emitter is implemented today; other builders build as Gutenberg with a warning. Pass a builder to nibwp/figma-pro-convert.';
    return $out;
}

/* ── analyze ─────────────────────────────────────────────────────────────── */
wp_register_ability('nibwp/figma-pro-analyze', [
    'label'       => __('Figma Pro — analyze a frame', 'nibwp'),
    'description' => __('Read-only. Fetch a Figma frame/component by URL and report the detected structure (sections, containers, text, images) + warnings, without creating anything. Use before convert to confirm the target.', 'nibwp'),
    'category'    => 'content',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'url' => ['type' => 'string', 'description' => 'Figma frame/component link, e.g. https://www.figma.com/design/KEY/Name?node-id=1-234'],
        ],
        'required' => ['url'],
        'additionalProperties' => false,
    ],
    'execute_callback'    => 'nibwp_figma_pro_ability_analyze',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function nibwp_figma_pro_ability_analyze(array $input): array|WP_Error
{
    $gate = nibwp_figma_pro_gate();
    if (is_wp_error($gate)) {
        return $gate;
    }
    $url = trim((string) ($input['url'] ?? ''));
    if ($url === '') {
        return new WP_Error('no_url', 'Provide a Figma frame URL.');
    }
    return nibwp_figma_analyze($url);
}

/* ── pull (cache a frame into the local library — the primary verb) ──────── */
wp_register_ability('nibwp/figma-pull', [
    'label'       => __('Figma Pro — pull a frame into the library', 'nibwp'),
    'description' => __('Pull a Figma frame/element (by URL) into NibWP\'s local library: renders + caches a 2× image, extracts the CSS tokens (colors + type ramp), and keeps the structure. Does NOT convert. Use this to "grab this frame" so the user + AI can decide later what to build from it, in any workflow or builder.', 'nibwp'),
    'category'    => 'content',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'url'  => ['type' => 'string', 'description' => 'Figma frame/element link (…?node-id=1-234).'],
            'name' => ['type' => 'string', 'description' => 'Optional label for the library.'],
        ],
        'required' => ['url'],
        'additionalProperties' => false,
    ],
    'execute_callback'    => 'nibwp_figma_pro_ability_pull',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true]],
]);

function nibwp_figma_pro_ability_pull(array $input): array|WP_Error
{
    $gate = nibwp_figma_pro_gate();
    if (is_wp_error($gate)) {
        return $gate;
    }
    $url = trim((string) ($input['url'] ?? ''));
    if ($url === '') {
        return new WP_Error('no_url', 'Provide a Figma frame URL.');
    }
    return nibwp_figma_pull($url, sanitize_text_field((string) ($input['name'] ?? '')));
}

/* ── list / get (the pulled library) ─────────────────────────────────────── */
wp_register_ability('nibwp/figma-list', [
    'label'       => __('Figma Pro — list pulled frames', 'nibwp'),
    'description' => __('List the Figma frames/elements already pulled into NibWP\'s library — id, name, cached image, and token summary. Reference these by id with nibwp/figma-get.', 'nibwp'),
    'category'    => 'content',
    'input_schema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
    'execute_callback'    => 'nibwp_figma_pro_ability_list',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function nibwp_figma_pro_ability_list(array $input): array|WP_Error
{
    $items = [];
    foreach (NIBWP_Figma_Library::all() as $e) {
        $handle = (string) ($e['handle'] ?? '');
        $items[] = [
            'id'        => (string) ($e['id'] ?? ''),
            'handle'    => $handle,
            'call_as'   => $handle !== '' ? '@figma/' . $handle : '',
            'name'      => (string) ($e['name'] ?? ''),
            'image_url' => (string) ($e['image_url'] ?? ''),
            'colors'    => array_values((array) ($e['tokens']['colors'] ?? [])),
            'url'       => (string) ($e['url'] ?? ''),
        ];
    }
    return [
        'count' => count($items),
        'items' => $items,
        'note'  => 'Users refer to a frame by its handle (e.g. "@figma/hero-section"). Pass that to nibwp/figma-get as `handle`, or the id as `id`.',
    ];
}

wp_register_ability('nibwp/figma-get', [
    'label'       => __('Figma Pro — get a pulled frame', 'nibwp'),
    'description' => __('Get one pulled frame by id: cached image, CSS tokens (colors + type ramp + :root css), structure summary, and the original Figma URL. Feed the tokens/image to a builder, or call nibwp/figma-pro-fetch with the url for builder-neutral HTML.', 'nibwp'),
    'category'    => 'content',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'id'     => ['type' => 'string', 'description' => 'Library id (from nibwp/figma-list).'],
            'handle' => ['type' => 'string', 'description' => 'Human handle the user says, e.g. "hero-section" or "@figma/hero-section". Either this or id.'],
        ],
        'additionalProperties' => false,
    ],
    'execute_callback'    => 'nibwp_figma_pro_ability_get',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function nibwp_figma_pro_ability_get(array $input): array|WP_Error
{
    $id     = trim((string) ($input['id'] ?? ''));
    $handle = trim((string) ($input['handle'] ?? ''));
    if ($id === '' && $handle === '') {
        return new WP_Error('no_ref', 'Provide an id or a handle. Use nibwp/figma-list to see what is pulled.');
    }

    $entry = null;
    if ($id !== '') {
        $entry = NIBWP_Figma_Library::get($id);
    }
    if ($entry === null && $handle !== '') {
        // Accept the spoken form "@figma/hero-section" as well as the bare slug.
        $entry = NIBWP_Figma_Library::by_handle(preg_replace('#^@?figma/#', '', $handle) ?? $handle);
    }
    if ($entry === null) {
        return new WP_Error('not_found', 'No pulled frame matches that id/handle. Use nibwp/figma-list.');
    }
    return $entry;
}

/* ── fetch (builder-neutral artifact) ────────────────────────────────────── */
wp_register_ability('nibwp/figma-pro-fetch', [
    'label'       => __('Figma Pro — fetch neutral artifact', 'nibwp'),
    'description' => __('Read a Figma frame/component (by URL) and return builder-NEUTRAL semantic HTML + design summary + the recommended builder ability for this site. This is the hand-off artifact: pass the html to any active builder skill\'s html-to-* ability (etchwp-pro / elementor-pro / bricks-pro / oxygen / kadence-pro) to build natively, or use nibwp/figma-pro-convert to route + persist. Read-only; creates nothing.', 'nibwp'),
    'category'    => 'content',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'url' => ['type' => 'string', 'description' => 'Figma frame/component link (…?node-id=1-234).'],
            'handle' => ['type' => 'string', 'description' => 'A frame already pulled into the library, by its handle (@figma/home, or just "home") or its name. Use this instead of url when the user refers to something they have already pulled — call nibwp/figma-list to see what is there.'],
        ],
        'additionalProperties' => false,
    ],
    'execute_callback'    => 'nibwp_figma_pro_ability_fetch',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function nibwp_figma_pro_ability_fetch(array $input): array|WP_Error
{
    $gate = nibwp_figma_pro_gate();
    if (is_wp_error($gate)) {
        return $gate;
    }
    $url = nibwp_figma_pro_resolve_ref($input);
    if (is_wp_error($url)) {
        return $url;
    }
    return nibwp_figma_fetch($url);
}

/**
 * Turn whatever the caller gave us into a Figma URL.
 *
 * A frame that has been pulled is normally referred to by its handle, because
 * that is what the library advertises and what the user sees. Accepting only a
 * URL meant the agent had to ask for a link the user had already imported —
 * and, having no way to know it, asked for something they could not supply.
 *
 * @param array<string,mixed> $input
 * @return string|WP_Error
 */
function nibwp_figma_pro_resolve_ref(array $input)
{
    $url = trim((string) ($input['url'] ?? ''));
    if ($url !== '' && preg_match('#^https?://#i', $url) === 1) {
        return $url;
    }

    // A handle may arrive in `handle`, or in `url` when the caller reached for
    // the only field it knew about.
    $ref = trim((string) ($input['handle'] ?? ''));
    if ($ref === '') {
        $ref = $url;
    }
    if ($ref === '') {
        return new WP_Error('no_url', 'Provide a Figma frame URL, or the handle of a frame already pulled into the library (see nibwp/figma-list).');
    }

    if (!class_exists('NIBWP_Figma_Library')) {
        return new WP_Error('figma_unavailable', 'The Figma library is not available on this site.');
    }
    $entry = NIBWP_Figma_Library::resolve($ref);
    if ($entry === null) {
        $known = NIBWP_Figma_Library::known_handles();
        return new WP_Error(
            'figma_unknown_handle',
            $known === []
                ? sprintf('No frame matches "%s", and nothing has been pulled into the library yet. Pull one first with nibwp/figma-pull.', $ref)
                : sprintf('No frame matches "%s". Pulled frames on this site: %s.', $ref, implode(', ', $known))
        );
    }
    $resolved = (string) ($entry['url'] ?? '');
    if ($resolved === '') {
        return new WP_Error('figma_no_url', sprintf('The frame "%s" is in the library but has no source URL recorded.', $ref));
    }
    return $resolved;
}

/* ── convert ─────────────────────────────────────────────────────────────── */
wp_register_ability('nibwp/figma-pro-convert', [
    'label'       => __('Figma Pro — convert to WordPress', 'nibwp'),
    'description' => __('Convert a Figma frame/component (by URL) into WordPress. ROUTER: fetches the design, detects the active builder, and either (a) returns a hand-off {html, next_ability} for the active builder skill (etchwp/elementor/bricks/oxygen/kadence) to build natively, or (b) with builder="gutenberg" (or when no builder skill is present) persists a core-blocks DRAFT directly. Sideloads images. dry_run=true reports the structure + route without building.', 'nibwp'),
    'category'    => 'content',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'url'     => ['type' => 'string', 'description' => 'Figma frame/component link with a selected node (…?node-id=1-234).'],
            'handle'  => ['type' => 'string', 'description' => 'A frame already pulled into the library, by handle (@figma/home, or just "home") or name. Use this when the user refers to something they have already pulled; nibwp/figma-list shows what is there.'],
            'title'   => ['type' => 'string', 'description' => 'Optional page title. Defaults to the Figma frame name.'],
            'builder' => ['type' => 'string', 'description' => 'auto | gutenberg | etchwp | bricks | elementor | kadence. With a real builder this HANDS OFF: it returns {html, next_ability} for that builder\'s own skill to build natively, and writes nothing itself. Only gutenberg persists here, as a core-blocks draft. It never silently emits Gutenberg for a builder you asked for.', 'default' => 'auto'],
            'dry_run' => ['type' => 'boolean', 'description' => 'true = validate + report, do NOT create the draft.', 'default' => false],
            '_preflight_token' => ['type' => 'string', 'description' => 'Token from nibwp/skill-preflight { skill_id:"figma-pro" }. Required to persist (dry_run=false).'],
        ],
        'additionalProperties' => false,
    ],
    'execute_callback'    => 'nibwp_figma_pro_ability_convert',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function nibwp_figma_pro_ability_convert(array $input): array|WP_Error
{
    $gate = nibwp_figma_pro_gate();
    if (is_wp_error($gate)) {
        return $gate;
    }
    $url = nibwp_figma_pro_resolve_ref($input);
    if (is_wp_error($url)) {
        return $url;
    }
    $title   = sanitize_text_field((string) ($input['title'] ?? ''));
    $builder = sanitize_key((string) ($input['builder'] ?? 'auto'));
    $dry_run = (bool) ($input['dry_run'] ?? false);

    // Persisting requires a valid preflight token (same contract as every Pro
    // skill): the agent must run nibwp/skill-preflight { skill_id:"figma-pro" }
    // so target/mode questions are answered before anything is written.
    if (!$dry_run) {
        $raw_token = (string) ($input['_preflight_token'] ?? '');
        if (!function_exists('nibwp_skill_preflight_consume_token')) {
            require_once __DIR__ . '/../../../abilities/skill-preflight.php';
        }
        $token_payload = nibwp_skill_preflight_consume_token($raw_token, 'figma-pro');
        if (is_wp_error($token_payload)) {
            return [
                'requires_user_input' => true,
                'question'    => 'Run nibwp/skill-preflight { skill_id:"figma-pro" } first to obtain a _preflight_token.',
                'next_action' => 'call_preflight',
                'error'       => $token_payload->get_error_message(),
            ];
        }
    }

    // allow_handoff=true: real builders route to their own html-to-* ability;
    // gutenberg / no-builder persists a core-blocks draft directly.
    return nibwp_figma_do_convert($url, $title, $builder !== '' ? $builder : 'auto', $dry_run, true);
}
