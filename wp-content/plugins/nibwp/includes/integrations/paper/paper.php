<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * NIBWP Paper integration — bootstrap.
 *
 * Paper is a design tool whose MCP server is LOCAL ONLY. Per its own docs
 * (https://paper.design/docs/mcp, read 2 Oct 2026) the server is started by the
 * Paper desktop app, speaks stdio through the `paper mcp` CLI with a legacy HTTP
 * endpoint on http://127.0.0.1:29979/mcp, needs no API key, no OAuth and no
 * token, and there is no cloud REST API for Paper files at all.
 *
 * So this integration is deliberately NOT a client. A WordPress site cannot
 * reach 127.0.0.1 on a designer's laptop, and there is no hosted endpoint to
 * reach instead. The agent is the bridge: it holds both MCP connections at once
 * — Paper locally, NibWP remotely — reads the design out of Paper itself, and
 * hands the result to us. Everything here is the receiving half: ingest,
 * normalise, library, convert, tokens, assets, audit.
 *
 * That shape is why there is no credential anywhere in this file. The Figma
 * integration stores an encrypted PAT because it calls api.figma.com itself;
 * there is nothing of the sort to store for Paper, and inventing an "ingest key"
 * or an unauthenticated route would only add a new way into the site. Every
 * action goes through nibwp_permission_callback plus a capability, exactly like
 * the rest of the hub abilities.
 *
 * Reuse: the normalizer emits the same NDO 1.0 the Figma normalizer emits, so
 * the existing emitters (core blocks, neutral HTML) and every builder skill that
 * consumes that HTML work for Paper unchanged. We do not grow a second copy of
 * the same conversion code.
 */

require_once __DIR__ . '/class-paper-ingest.php';
require_once __DIR__ . '/class-paper-normalize.php';
require_once __DIR__ . '/class-paper-library.php';
require_once __DIR__ . '/class-paper-tokens.php';
require_once __DIR__ . '/class-paper-assets.php';
// The wp-admin screen and its form handlers. Kept out of this file because the
// abilities are the product and the screen is a view onto them.
require_once __DIR__ . '/paper-page.php';

/* ───────────────────────────── capabilities ───────────────────────────── */

/**
 * Reading the library means reading somebody's unreleased design work, so it is
 * an author-level capability rather than a logged-in one — but it is not the bar
 * for writing a page.
 */
const NIBWP_PAPER_CAP_READ = 'edit_posts';

/** Ingesting, renaming, deleting a design, and writing a page from one. */
const NIBWP_PAPER_CAP_WRITE = 'edit_posts';

/** Putting an exported image into the Media Library. */
const NIBWP_PAPER_CAP_UPLOAD = 'upload_files';

/**
 * Writing the site's design tokens. Site-wide styling, so the theme bar —
 * manage_options is accepted as well because an administrator holds both and
 * refusing them would be theatre.
 */
const NIBWP_PAPER_CAP_SITE = 'edit_theme_options';

/** How many rows any listing returns, whatever was asked for. */
const NIBWP_PAPER_MAX_ROWS = 200;

/** The default page size of every listing, so an unbounded read is impossible. */
const NIBWP_PAPER_DEFAULT_ROWS = 50;

/* ───────────────────────────── availability ───────────────────────────── */

/** Is the Paper integration licensed on this install? */
function nibwp_paper_unlocked(): bool
{
    return !function_exists('nibwp_integration_is_unlocked') || nibwp_integration_is_unlocked('paper');
}

/** Licensed AND switched on under Integrations. */
function nibwp_paper_enabled(): bool
{
    if (!nibwp_paper_unlocked()) {
        return false;
    }

    return !function_exists('nibwp_is_integration_enabled') || nibwp_is_integration_enabled('paper');
}

/**
 * Make the shared NDO emitters available.
 *
 * They live beside the Figma integration because that is where the format was
 * first defined, and they are plain classes with no Figma state of their own —
 * no token, no HTTP, nothing to connect. Loaded lazily and by class_exists so a
 * build that ships one directory and not the other degrades into an honest
 * refusal instead of a fatal.
 */
function nibwp_paper_shared_emitters_loaded(): bool
{
    $shared = [
        'NIBWP_Figma_Gutenberg' => 'class-figma-gutenberg.php',
        'NIBWP_Figma_Html'      => 'class-figma-html.php',
    ];

    foreach ($shared as $class => $file) {
        if (class_exists($class)) {
            continue;
        }
        $path = dirname(__DIR__) . '/figma/' . $file;
        if (is_readable($path)) {
            require_once $path;
        }
    }

    return class_exists('NIBWP_Figma_Gutenberg') && class_exists('NIBWP_Figma_Html');
}

/**
 * The one guard, run before anything else on every action.
 *
 * There is no plugin to detect here and no connection to test — what can be
 * absent is the integration itself (unlicensed or switched off) and the shared
 * pipeline this bridge is built on. Both answer with one code so a caller can
 * branch on it.
 */
function nibwp_paper_guard(): ?WP_Error
{
    if (!nibwp_paper_enabled()) {
        return new WP_Error(
            'nibwp_paper_unavailable',
            __('The Paper bridge is not available on this site. Activate a NibWP Pro or Bundle license and switch Paper on under Integrations.', 'nibwp'),
            ['status' => 400]
        );
    }

    if (!class_exists('NIBWP_Paper_Normalize') || !class_exists('NIBWP_Paper_Library') || !nibwp_paper_shared_emitters_loaded()) {
        return new WP_Error(
            'nibwp_paper_unavailable',
            __('The shared design pipeline is not installed, so a Paper design cannot be normalised or converted on this site.', 'nibwp'),
            ['status' => 500]
        );
    }

    return null;
}

/** A refusal about the request itself. */
function nibwp_paper_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error('nibwp_paper_' . $code, $message, ['status' => $status]);
}

/** A refusal about authority rather than about the request. */
function nibwp_paper_forbidden(string $what, string $cap): WP_Error
{
    return new WP_Error(
        'nibwp_paper_forbidden',
        sprintf(
            /* translators: 1: what was being attempted, 2: capability name */
            __('%1$s needs the %2$s capability, which this account does not have.', 'nibwp'),
            $what,
            $cap
        ),
        ['status' => 403]
    );
}

/**
 * Which capability each action needs.
 *
 * A map rather than a switch inside each handler, because the check has to run
 * before any of them sees the input: an unauthorised caller must not learn from
 * the refusal which design ids exist.
 *
 * @return array<string, string>
 */
function nibwp_paper_action_caps(): array
{
    return [
        'status'             => NIBWP_PAPER_CAP_READ,
        'setup_instructions' => NIBWP_PAPER_CAP_READ,
        // Pairing is the agent saying "I can see Paper", so it sits with the
        // account that does the handing over. Unpairing clears state the whole
        // site reads, which is the theme bar — the same asymmetry WordPress uses
        // between writing a post and changing the site.
        'pair'               => NIBWP_PAPER_CAP_WRITE,
        'unpair'             => NIBWP_PAPER_CAP_SITE,
        'ingest'             => NIBWP_PAPER_CAP_WRITE,
        'validate_payload'   => NIBWP_PAPER_CAP_READ,
        'list_designs'       => NIBWP_PAPER_CAP_READ,
        'get_design'         => NIBWP_PAPER_CAP_READ,
        'get_ndo'            => NIBWP_PAPER_CAP_READ,
        'rename_design'      => NIBWP_PAPER_CAP_WRITE,
        'delete_design'      => NIBWP_PAPER_CAP_WRITE,
        'preview_convert'    => NIBWP_PAPER_CAP_READ,
        'convert'            => NIBWP_PAPER_CAP_WRITE,
        'list_conversions'   => NIBWP_PAPER_CAP_READ,
        'list_builders'      => NIBWP_PAPER_CAP_READ,
        'list_fonts'         => NIBWP_PAPER_CAP_READ,
        'list_tokens'        => NIBWP_PAPER_CAP_READ,
        'token_targets'      => NIBWP_PAPER_CAP_READ,
        'sync_tokens'        => NIBWP_PAPER_CAP_SITE,
        'attach_asset'       => NIBWP_PAPER_CAP_UPLOAD,
        'list_assets'        => NIBWP_PAPER_CAP_READ,
        'audit'              => NIBWP_PAPER_CAP_READ,
    ];
}

/**
 * The actions this hub offers. Order is the order a caller should discover them.
 *
 * @return array<int, string>
 */
function nibwp_paper_actions(): array
{
    return array_keys(nibwp_paper_action_caps());
}

/**
 * The capability gate.
 *
 * sync_tokens is the one action where two capabilities are both legitimate:
 * edit_theme_options is the honest bar for site styling, and an administrator
 * holds manage_options as well.
 */
function nibwp_paper_require(string $action): ?WP_Error
{
    $caps = nibwp_paper_action_caps();
    $cap = $caps[$action] ?? NIBWP_PAPER_CAP_SITE;

    if ($cap === NIBWP_PAPER_CAP_SITE) {
        if (current_user_can(NIBWP_PAPER_CAP_SITE) || current_user_can('manage_options')) {
            return null;
        }

        return nibwp_paper_forbidden($action, NIBWP_PAPER_CAP_SITE);
    }

    return current_user_can($cap) ? null : nibwp_paper_forbidden($action, $cap);
}

/* ───────────────────────────── small helpers ───────────────────────────── */

/** Clamp a row count that arrived over the wire. Every listing goes through it. */
function nibwp_paper_limit(mixed $value, int $default = NIBWP_PAPER_DEFAULT_ROWS): int
{
    $limit = is_numeric($value) ? (int) $value : $default;

    return max(1, min(NIBWP_PAPER_MAX_ROWS, $limit));
}

/** Clamp an offset. */
function nibwp_paper_offset(mixed $value): int
{
    return is_numeric($value) ? max(0, (int) $value) : 0;
}

/** A plain string out of whatever arrived, with no surprise "Array". */
function nibwp_paper_str(mixed $value, int $max = 200): string
{
    if (!is_scalar($value)) {
        // (string) [] is the word "Array" — drop a non-scalar instead of casting it.
        return '';
    }
    $out = trim((string) $value);

    return $out === '' ? '' : mb_substr($out, 0, $max);
}

/** Option name holding one design's NDO. Kept out of the index option. */
function nibwp_paper_ndo_option(string $design_id): string
{
    return 'nibwp_paper_ndo_' . $design_id;
}

/** The page this integration renders in wp-admin. */
function nibwp_paper_page_url(): string
{
    return admin_url('admin.php?page=nibwp-paper');
}

/**
 * The setup lines a designer needs, as copy-paste commands.
 *
 * Verbatim from Paper's own MCP documentation. We cannot shorten them to a
 * "connect" button: the server is on the designer's machine, so the thing being
 * configured is the agent, not this site.
 *
 * @return array<string, mixed>
 */
function nibwp_paper_setup_lines(): array
{
    return [
        'http' => 'claude mcp add paper --transport http http://127.0.0.1:29979/mcp --scope user',
        'stdio' => 'claude mcp add paper --scope user -- paper mcp',
        'endpoint' => 'http://127.0.0.1:29979/mcp',
        'requires' => __('The Paper desktop app must be running — it is what starts the MCP server.', 'nibwp'),
        'no_credential' => __('Paper\'s MCP server takes no API key, no OAuth and no token. There is nothing to paste here, and nothing is stored.', 'nibwp'),
    ];
}

/**
 * What we can and cannot see from this side, said plainly.
 *
 * @return array<string, mixed>
 */
function nibwp_paper_verifiability(): array
{
    return [
        'can_verify' => [
            __('Which builders are installed and ready on this site.', 'nibwp'),
            __('What is already in the design library here.', 'nibwp'),
            __('Which design-token targets this site actually has.', 'nibwp'),
            __('What capability the calling account holds.', 'nibwp'),
        ],
        'cannot_verify' => [
            __('Whether the Paper desktop app is running.', 'nibwp'),
            __('Whether the Paper MCP server is reachable.', 'nibwp'),
            __('Which Paper file or frame is open, or what it contains.', 'nibwp'),
        ],
        'why' => __('Paper\'s MCP server listens on 127.0.0.1 on the designer\'s own machine. This site cannot reach it, and Paper has no cloud API to ask instead — so the design travels through the agent, which is connected to both ends.', 'nibwp'),
    ];
}

/* ───────────────────────────── the pairing record ───────────────────────── */

/**
 * Where the pairing record lives.
 *
 * It holds no credential, because there is none to hold: what it records is that
 * an agent, signed in as a known account, reported it could see Paper's MCP
 * server and which tools that server offered.
 */
const NIBWP_PAPER_BRIDGE_OPTION = 'nibwp_paper_bridge';

/** How many reported tool names we keep. Paper offers a couple of dozen. */
const NIBWP_PAPER_MAX_TOOLS = 64;

/**
 * The pairing record, or an empty array.
 *
 * @return array<string, mixed>
 */
function nibwp_paper_bridge(): array
{
    $saved = get_option(NIBWP_PAPER_BRIDGE_OPTION, []);

    return is_array($saved) ? $saved : [];
}

/**
 * Has an agent ever proved it could see Paper from here?
 *
 * This is what the Integrations card and the admin screen call "connected", and
 * it is a weaker claim than Figma's: there, a token either authenticates or it
 * does not, and the site finds out itself. Here the site is not a party to the
 * connection at all, so the honest answer is "an agent reported this, on this
 * date, as this account" — which is what the record carries and what the screen
 * shows.
 */
function nibwp_paper_is_connected(): bool
{
    return (int) (nibwp_paper_bridge()['paired_at'] ?? 0) > 0;
}

/**
 * Record a pairing. The agent calls this after checking its own Paper connection.
 *
 * Evidence is required rather than optional: without at least one tool name the
 * call is refused, so pairing cannot succeed for an agent that never actually
 * asked Paper anything. That is the whole value of the record.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_paper_pair(array $input)
{
    $tools = [];
    foreach ((array) ($input['paper_tools'] ?? []) as $tool) {
        // nibwp_paper_str() drops a non-scalar rather than casting it, which is
        // what keeps a nested array out of here as the literal word "Array" —
        // a string that would read on screen as a tool Paper offers.
        $name = nibwp_paper_str($tool, 80);
        if ($name !== '') {
            $tools[] = $name;
        }
        if (count($tools) >= NIBWP_PAPER_MAX_TOOLS) {
            break;
        }
    }
    $tools = array_values(array_unique($tools));

    if ($tools === []) {
        return nibwp_paper_err(
            'tools_required',
            __('Pairing needs the list of tools your Paper MCP connection exposes. List them first — if that fails, the Paper desktop app is not running and there is nothing to pair.', 'nibwp')
        );
    }

    $transport = nibwp_paper_str($input['transport'] ?? '', 10);

    $record = [
        'paired_at' => time(),
        'paired_by' => get_current_user_id(),
        'agent' => nibwp_paper_str($input['agent'] ?? '', 120),
        'paper_version' => nibwp_paper_str($input['paper_version'] ?? '', 40),
        'transport' => in_array($transport, ['http', 'stdio'], true) ? $transport : '',
        'tools' => $tools,
        'tool_count' => count($tools),
        // Flagged as reported rather than verified, so nothing downstream can
        // quietly present it as a check this site performed.
        'reported' => true,
    ];

    update_option(NIBWP_PAPER_BRIDGE_OPTION, $record, false);

    return [
        'paired' => true,
        'bridge' => $record,
        'next' => __('Select a frame in Paper, read it, and send it to ingest.', 'nibwp'),
        'note' => __('Recorded as reported by the agent. This site cannot reach Paper to confirm it.', 'nibwp'),
    ];
}

/**
 * Clear the pairing record. The library is deliberately untouched — unpairing
 * says the bridge is no longer set up, not that the work should be thrown away.
 *
 * @return array<string, mixed>
 */
function nibwp_paper_unpair(): array
{
    $was = nibwp_paper_is_connected();
    delete_option(NIBWP_PAPER_BRIDGE_OPTION);

    return [
        'unpaired' => true,
        'was_paired' => $was,
        'library_kept' => true,
    ];
}

/* ───────────────────────────── the hub ability ───────────────────────────── */

/*
 * On the Abilities API's own init pass, not at file load.
 *
 * This file is required from nibwp.php when the plugin loads, which is before
 * `init`: registering there is refused outright (the ability simply never
 * existed) and every __() in the arguments below fired before any textdomain
 * was loaded, which is what the just-in-time translation notice was reporting.
 * The premium integrations avoid both by being required from inside this hook
 * (premium/bootstrap.php); this one has an admin screen to declare at load, so
 * it stays required early and defers the registration instead.
 */
add_action('wp_abilities_api_init', static function (): void {
    wp_register_ability('nibwp/paper-manage', [
        'label' => __('Paper – Design Bridge', 'nibwp'),
        'description' => 'Receive a design read out of Paper by the agent and turn it into WordPress. Paper\'s MCP server is local to the designer\'s machine and Paper has no cloud API, '
            . 'so this site cannot fetch anything itself: YOU read the design through your own Paper MCP connection and hand it to ingest. '
            . 'From there: normalise to the shared design object, keep it in a library, convert it to core blocks or hand neutral HTML to the active builder\'s skill, '
            . 'sync its colours and type scale into the site\'s real token system, put exported images into the Media Library, and audit what was ingested but never used. '
            . 'No credential is stored or returned, because Paper does not issue one.',
        'category' => 'design',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => nibwp_paper_actions(),
                    'description' => 'Which operation to run. Start with status.',
                ],
                'design' => [
                    'type' => 'object',
                    'description' => 'For ingest / validate_payload: what you read out of Paper. Keys: name, paper_url, file_id, node_id, source (tree|html|jsx), tree (node array), html, jsx, css, computed (nodeId → CSS map), fonts, tokens, assets, screenshot_url.',
                    'additionalProperties' => true,
                ],
                'design_id' => ['type' => 'string', 'description' => 'A design in the library. Its id, its @paper/handle, or its name.'],
                'name' => ['type' => 'string', 'description' => 'For rename_design: the new label.'],
                'builder' => ['type' => 'string', 'description' => 'For convert / preview_convert: gutenberg, etchwp, bricks, elementor, kadence, oxygen, or auto.'],
                'title' => ['type' => 'string', 'description' => 'For convert: the page title. Defaults to the design name.'],
                'post_id' => ['type' => 'integer', 'description' => 'For convert: replace the content of this existing page instead of creating a draft.'],
                'dry_run' => ['type' => 'boolean', 'description' => 'For convert: default true. Nothing is written until you pass false.'],
                'target' => ['type' => 'string', 'description' => 'For sync_tokens: acss, theme_json, or global_styles. Left out, the best available one.'],
                'tokens' => ['type' => 'object', 'description' => 'For sync_tokens: override the design\'s token map.', 'additionalProperties' => true],
                'url' => ['type' => 'string', 'description' => 'For attach_asset: the image URL the agent exported out of Paper.'],
                'alt' => ['type' => 'string', 'description' => 'For attach_asset: alt text. Write it — an image with no alt is a defect.'],
                'filename' => ['type' => 'string', 'description' => 'For attach_asset: the filename to store it under.'],
                'ref' => ['type' => 'string', 'description' => 'For attach_asset: the design\'s own reference for this image, so the design and the attachment stay linked.'],
                'limit' => ['type' => 'integer', 'description' => 'Rows to return. Default 50, max 200.'],
                'offset' => ['type' => 'integer', 'description' => 'Where a listing starts.'],
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
        'execute_callback' => 'nibwp_paper_manage',
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true],
            'annotations' => [
                'instructions' => implode("\n", [
                    'Paper → WordPress. You are the bridge, not a client of ours.',
                    '',
                    'THE ARCHITECTURE, because it changes how you work:',
                    'Paper\'s MCP server runs on the designer\'s own machine (127.0.0.1:29979, started',
                    'by the Paper desktop app) and Paper has no cloud API. This WordPress site cannot',
                    'fetch a Paper file — not with a token, not with a URL, not at all. You hold both',
                    'connections, so YOU read the design through your Paper MCP tools and pass what you',
                    'read to ingest. There is no credential to set up on this side and nothing to',
                    'connect. Do not look for a connect action; there is not one.',
                    '',
                    'START WITH status. It reports which builders are ready here, what is already in',
                    'the library, what capability you hold, and the exact setup line a designer needs.',
                    'It is honest that it cannot see whether Paper is running — only you can.',
                    '',
                    'THE ROUTINE:',
                    '1) Read the design in Paper (your own MCP connection). Export what you can:',
                    '   the node tree, computed styles, the JSX or HTML, fonts, colours, image URLs.',
                    '2) ingest { design: {...} }. That normalises it, stores it, and returns a',
                    '   design_id plus every warning. Ingest is the only way a design enters.',
                    '3) preview_convert { design_id } to see the markup without writing anything.',
                    '4) convert { design_id, builder } — dry_run defaults to TRUE. Read the preview,',
                    '   then re-send with dry_run:false to actually create the draft.',
                    '5) attach_asset for each exported image, with alt text you actually wrote.',
                    '6) sync_tokens only when the user asked for the site\'s styling to change.',
                    '',
                    'INGEST IS BOUNDED. Oversized, too deep, or too many nodes comes back refused by',
                    'name rather than truncated silently — split the design and ingest the parts. A',
                    'payload carrying PHP tags or a serialised object is refused outright; script,',
                    'style and event handlers are dropped and reported in warnings.',
                    '',
                    'BUILDERS: convert with builder:"gutenberg" writes core blocks directly. For a',
                    'real page builder, convert returns neutral HTML plus the builder skill\'s own',
                    'html-to-* ability to continue with — that ability validates and persists a native',
                    'payload you synthesize, it does not convert HTML for you.',
                    '',
                    'TOKENS: sync_tokens writes site-wide styling and takes edit_theme_options. Check',
                    'token_targets first; if the site has no ACSS, no theme.json and no global styles,',
                    'it refuses and says so rather than inventing a target.',
                    '',
                    'audit is the question "what did we ingest and never use": designs never converted,',
                    'pages whose design has been re-ingested since, assets a design references but the',
                    'library does not have, and tokens that match nothing on this site.',
                ]),
                'readonly' => false,
                'destructive' => true,
                'idempotent' => false,
            ],
        ],
    ]);
});

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_paper_manage(array $input): array|WP_Error
{
    // The guard first: whether this integration is usable at all is not a secret,
    // and it must answer before a capability is consulted or any input is read.
    $guard = nibwp_paper_guard();
    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $action = isset($input['action']) ? nibwp_paper_str($input['action'], 64) : '';

    if (!in_array($action, nibwp_paper_actions(), true)) {
        return nibwp_paper_err(
            'unknown_action',
            sprintf(
                /* translators: 1: action name, 2: list of valid actions */
                __('Unknown Paper action "%1$s". Valid actions: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_paper_actions())
            )
        );
    }

    // Authority before validation. The other way round, a refusal about a bad id
    // tells an unauthorised caller that the id was recognised.
    $denied = nibwp_paper_require($action);
    if ($denied instanceof WP_Error) {
        return $denied;
    }

    // An ingest payload is attacker-shaped by definition and the parsers it feeds
    // are recursive. One catch here turns anything they raise into a named refusal
    // instead of a fatal that takes the REST request down.
    try {
        return nibwp_paper_dispatch($action, $input);
    } catch (\Throwable $error) {
        return nibwp_paper_err(
            'internal_error',
            sprintf(
                /* translators: 1: action name, 2: error message */
                __('Paper could not complete %1$s: %2$s', 'nibwp'),
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
function nibwp_paper_dispatch(string $action, array $input): array|WP_Error
{
    $result = match ($action) {
        'status' => nibwp_paper_status(),
        'setup_instructions' => nibwp_paper_setup_instructions(),
        'pair' => nibwp_paper_pair($input),
        'unpair' => nibwp_paper_unpair(),
        'ingest' => nibwp_paper_ingest($input),
        'validate_payload' => nibwp_paper_validate_payload($input),
        'list_designs' => nibwp_paper_list_designs($input),
        'get_design' => nibwp_paper_get_design($input),
        'get_ndo' => nibwp_paper_get_ndo($input),
        'rename_design' => nibwp_paper_rename_design($input),
        'delete_design' => nibwp_paper_delete_design($input),
        'preview_convert' => nibwp_paper_preview_convert($input),
        'convert' => nibwp_paper_convert($input),
        'list_conversions' => nibwp_paper_list_conversions($input),
        'list_builders' => nibwp_paper_list_builders(),
        'list_fonts' => nibwp_paper_list_fonts($input),
        'list_tokens' => nibwp_paper_list_tokens($input),
        'token_targets' => nibwp_paper_token_targets(),
        'sync_tokens' => nibwp_paper_sync_tokens($input),
        'attach_asset' => nibwp_paper_attach_asset($input),
        'list_assets' => nibwp_paper_list_assets($input),
        'audit' => nibwp_paper_audit($input),
        default => nibwp_paper_err('unhandled_action', __('Unhandled action.', 'nibwp')),
    };

    return $result instanceof WP_Error ? $result : ['action' => $action] + $result;
}

/* ───────────────────────────── readiness ───────────────────────────── */

/**
 * Which builders are installed and which can actually take a handoff.
 *
 * Reuses the shared detection rather than probing constants again: a builder's
 * own skill has to be enabled before a handoff means anything, and "installed"
 * is not "ready".
 *
 * @return array<string, mixed>
 */
function nibwp_paper_detect_builders(): array
{
    if (function_exists('nibwp_figma_detect_builders')) {
        return nibwp_figma_detect_builders();
    }

    // Standalone build: core blocks always work, nothing else is claimed.
    return [
        'active_builders'  => ['gutenberg'],
        'ready_builders'   => ['gutenberg'],
        'blocked_builders' => [],
        'enhancers_active' => [],
        'recommended'      => 'gutenberg',
    ];
}

/**
 * builder key → that builder skill's own HTML→native ability.
 *
 * @return array<string, string>
 */
function nibwp_paper_builder_ability_map(): array
{
    if (function_exists('nibwp_figma_builder_ability_map')) {
        return nibwp_figma_builder_ability_map();
    }

    return [];
}

/** 'auto' → the best installed builder, else core blocks. */
function nibwp_paper_resolve_builder(string $requested, array $active): string
{
    $requested = strtolower($requested === '' ? 'auto' : $requested);
    if ($requested !== 'auto') {
        return $requested;
    }
    foreach (['etchwp', 'oxygen', 'bricks', 'elementor', 'kadence'] as $builder) {
        if (in_array($builder, $active, true)) {
            return $builder;
        }
    }

    return 'gutenberg';
}

/** @return array<string, mixed> */
function nibwp_paper_status(): array
{
    $builders = nibwp_paper_detect_builders();
    $library = NIBWP_Paper_Library::summary();

    return [
        'bridge' => 'agent',
        'ready_to_receive' => true,
        // Whether an agent has ever reported seeing Paper from here. Carries no
        // credential — there is none — so the record goes back whole, including
        // the fact that it is a report rather than a check.
        'paired' => nibwp_paper_is_connected(),
        'pairing' => nibwp_paper_bridge(),
        'library' => $library,
        'builders' => $builders,
        'token_targets' => NIBWP_Paper_Tokens::targets(),
        'caller' => nibwp_paper_caller_caps(),
        'setup' => nibwp_paper_setup_lines(),
        'verifiability' => nibwp_paper_verifiability(),
        'limits' => NIBWP_Paper_Ingest::limits(),
        'note' => __('This site cannot fetch from Paper. Read the design with your own Paper MCP connection and send it to ingest.', 'nibwp'),
    ];
}

/**
 * What the calling account may do here. Capabilities, never identities.
 *
 * @return array<string, mixed>
 */
function nibwp_paper_caller_caps(): array
{
    return [
        'can_read' => current_user_can(NIBWP_PAPER_CAP_READ),
        'can_ingest' => current_user_can(NIBWP_PAPER_CAP_WRITE),
        'can_write_pages' => current_user_can(NIBWP_PAPER_CAP_WRITE),
        'can_upload' => current_user_can(NIBWP_PAPER_CAP_UPLOAD),
        'can_sync_tokens' => current_user_can(NIBWP_PAPER_CAP_SITE) || current_user_can('manage_options'),
    ];
}

/** @return array<string, mixed> */
function nibwp_paper_setup_instructions(): array
{
    return [
        'setup' => nibwp_paper_setup_lines(),
        'verifiability' => nibwp_paper_verifiability(),
        'steps' => [
            __('Open the Paper desktop app — it starts the MCP server. Nothing else starts it.', 'nibwp'),
            __('Add the Paper MCP server to your agent with one of the commands above.', 'nibwp'),
            __('Keep this site\'s NibWP MCP connection as it is. The agent needs both at once.', 'nibwp'),
            __('Read the design in Paper, then send it here with the ingest action.', 'nibwp'),
        ],
        'source' => 'https://paper.design/docs/mcp',
    ];
}

/**
 * builder slug → the name a person calls it.
 *
 * The same words helpers.php uses, because two surfaces naming one builder two
 * ways is its own kind of bug. That map cannot be called: it lives as a local
 * inside nibwp_builder_output_lines(), keyed by plugin file, and the detection
 * this panel reads speaks builder slugs. So the labels are repeated here and
 * nothing else is — the catalogue itself is derived, below.
 *
 * Brand names are not translated, because a product is called what it is called.
 * "Core blocks" is ordinary prose, and is. Capitalising a slug mechanically is
 * not an option either — it would put "Etchwp" in front of somebody who owns Etch.
 *
 * @return array<string, string>
 */
function nibwp_paper_builder_labels(): array
{
    return [
        'etchwp' => 'Etch',
        'oxygen' => 'Oxygen',
        'bricks' => 'Bricks',
        'elementor' => 'Elementor',
        'kadence' => 'Kadence Blocks',
        'builderius' => 'Builderius',
        'breakdance' => 'Breakdance',
        'voxel' => 'Voxel',
        'gutenberg' => __('Core blocks', 'nibwp'),
    ];
}

/**
 * Every builder that earns a card, in reading order, with the skill that serves it.
 *
 * Derived rather than typed out. A NibWP builder skill declares the builder it is
 * for in its own manifest — category page-builders, requires <integration key> —
 * so the catalogue of builders we work with is already on disk and cannot drift
 * from the skills that ship. Folded in with it: every builder the handoff map
 * names, everything the shared detection reported, and core blocks, which need
 * neither a plugin nor a skill and are therefore never derived from anything.
 *
 * The point of listing a builder that is not installed is that the panel should
 * teach. A site with no page builder still learns which ones a design could be
 * handed to, instead of reading as though nothing works.
 *
 * @param array<string, string> $map      builder slug → handoff ability
 * @param array<string, bool>   $detected slugs the shared detection named
 * @return array<string, string|null>     builder slug → skill id, or null
 */
function nibwp_paper_builder_catalog(array $map, array $detected): array
{
    $skills = ['gutenberg' => null];

    foreach ([array_keys($map), array_keys($detected)] as $slugs) {
        foreach ($slugs as $slug) {
            $slug = strtolower(nibwp_paper_str($slug, 40));
            if ($slug !== '' && !array_key_exists($slug, $skills)) {
                $skills[$slug] = null;
            }
        }
    }

    if (function_exists('nibwp_skills_discover')) {
        foreach (nibwp_skills_discover() as $skill) {
            if (!is_array($skill) || ($skill['category'] ?? '') !== 'page-builders') {
                continue;
            }
            $id = strtolower(nibwp_paper_str($skill['id'] ?? '', 60));
            if ($id === '') {
                continue;
            }
            foreach ((array) ($skill['requires'] ?? []) as $requires) {
                $slug = strtolower(nibwp_paper_str($requires, 40));
                // First claim wins. Two skills over one builder would leave the
                // card with two switches and no way to say which one to flip.
                if ($slug !== '' && ($skills[$slug] ?? null) === null) {
                    $skills[$slug] = $id;
                }
            }
        }
    }

    foreach ($skills as $slug => $skill) {
        if ($skill === null && $slug !== 'gutenberg') {
            // Nothing claimed this builder by manifest. The handoff ability name
            // is the other place the answer is written down.
            $skills[$slug] = nibwp_paper_builder_skill(nibwp_paper_str($map[$slug] ?? '', 160));
        }
    }

    // Preference order first — the order resolve_builder() routes in — then
    // anything else, then core blocks: the fallback is listed last so it never
    // reads as the recommendation.
    $ordered = [];
    foreach (['etchwp', 'oxygen', 'bricks', 'elementor', 'kadence'] as $slug) {
        if (array_key_exists($slug, $skills)) {
            $ordered[$slug] = $skills[$slug];
            unset($skills[$slug]);
        }
    }
    unset($skills['gutenberg']);
    foreach ($skills as $slug => $skill) {
        $ordered[$slug] = $skill;
    }
    $ordered['gutenberg'] = null;

    return $ordered;
}

/**
 * Which NibWP skill would perform a handoff, as far as this site can tell.
 *
 * Read out of the ability name rather than kept in a second list: these abilities
 * are named nibwp/<skill>-html-to-<something>, so the skill that owns one is
 * already written down in the map we were handed. The registry then has to
 * confirm that skill exists here — Oxygen is the live example of one that does
 * not — because naming a skill nobody can switch on produces a card telling
 * somebody to flip a switch that is not there.
 */
function nibwp_paper_builder_skill(string $ability): ?string
{
    if (!str_starts_with($ability, 'nibwp/')) {
        return null;
    }

    $slug = substr($ability, 6);
    $cut = strpos($slug, '-html-to-');
    if ($cut !== false) {
        $slug = substr($slug, 0, $cut);
    }
    if ($slug === '' || !function_exists('nibwp_skill_get')) {
        // No registry to ask. An unverified slug becomes a fix that leads
        // nowhere, so the caller gets the honest nothing instead.
        return null;
    }

    return nibwp_skill_get($slug) === null ? null : $slug;
}

/**
 * One card per builder a design can be handed to, and what to do about each.
 *
 * Every fact comes from a source that already owns it: the shared detection for
 * which builder is present and which handoff is live, the same availability check
 * it delegates to for the builders it does not probe, the skill registry for
 * whether a skill exists and is switched on. Nothing re-tests a constant here.
 * What this adds is the reading of those facts: "installed" is not "ready", and
 * not-ready has several causes that want different sentences and different
 * buttons. So `state` is derived and never guessed, `blocked_reason` says what
 * stands in the way, and `fix` is the one screen that changes it — null when
 * there is nothing honest to click, because a dead link in an admin panel costs
 * more than a missing one.
 *
 * The reasons are written here rather than passed through from the shared
 * detection, which phrases its own in English around the slug: a card must say
 * "Etch", in the reader's language, not "etchwp".
 *
 * @return array<string, mixed>
 */
function nibwp_paper_list_builders(): array
{
    $detect = nibwp_paper_detect_builders();
    $map = nibwp_paper_builder_ability_map();
    $labels = nibwp_paper_builder_labels();

    $slug_set = static function (mixed $list): array {
        $out = [];
        foreach ((array) $list as $key => $value) {
            // blocked_builders is keyed by slug with a sentence as its value;
            // the other two are plain lists. Both shapes answer the same
            // question, so take whichever side of the pair is the slug.
            $slug = strtolower(nibwp_paper_str(is_string($key) ? $key : $value, 40));
            if ($slug !== '') {
                $out[$slug] = true;
            }
        }

        return $out;
    };

    $active = $slug_set($detect['active_builders'] ?? []);
    $ready = $slug_set($detect['ready_builders'] ?? []);
    // A builder the shared detection calls blocked is one it FOUND: its loop
    // only ever blocks what it already detected as present. So this is a second
    // witness to "installed", not a category of its own.
    $blocked = $slug_set($detect['blocked_builders'] ?? []);

    $rows = [];
    foreach (nibwp_paper_builder_catalog($map, $active + $ready + $blocked) as $slug => $skill) {
        $label = $labels[$slug] ?? ucfirst($slug);
        $ability = isset($map[$slug]) ? nibwp_paper_str($map[$slug], 160) : '';
        // Null, not false: "we cannot read the toggle" and "the toggle is off"
        // are different facts, and only one of them is a thing to fix.
        $skill_enabled = null;
        if ($skill !== null && function_exists('nibwp_skill_is_enabled')) {
            $skill_enabled = nibwp_skill_is_enabled($skill);
        }

        // Core blocks are the one target that is always both: they ship with
        // WordPress, so a site with no page builder still has a usable target.
        //
        // For the rest, the shared detection answers first. It only probes the
        // builders its own conversion routes to, so for the others the same
        // availability check it delegates to is asked directly — the one source,
        // not a second guess at constants.
        $installed = $slug === 'gutenberg'
            || isset($active[$slug])
            || isset($blocked[$slug])
            || (function_exists('nibwp_is_integration_available') && nibwp_is_integration_available($slug));
        $is_ready = $slug === 'gutenberg' || (isset($ready[$slug]) && $installed);

        $reason = null;
        $fix = null;

        if (!$installed) {
            $state = 'missing';
        } elseif ($is_ready) {
            $state = 'ready';
        } elseif ($ability === '') {
            // A builder with a skill of its own that this bridge has no handoff
            // for. Its skill being on changes nothing here, so saying "enable
            // it" would be advice that does not help.
            $state = 'blocked';
            $reason = sprintf(
                /* translators: %s: builder name */
                __('%s is on this site, but this bridge has no handoff for it, so a design can only be built as core blocks.', 'nibwp'),
                $label
            );
        } elseif ($skill_enabled === false) {
            // One switch away, and the switch is ours — so say which one, and
            // link the screen it is on. This is the state the owner asked for.
            $state = 'installed';
            $reason = sprintf(
                /* translators: 1: builder name, 2: NibWP skill id */
                __('%1$s is on this site, but its NibWP skill (%2$s) is switched off, so there is nothing to hand the design to yet.', 'nibwp'),
                $label,
                $skill
            );
            $fix = [
                'label' => sprintf(
                    /* translators: %s: builder name */
                    __('Enable the %s skill', 'nibwp'),
                    $label
                ),
                // The Skills screen is where that toggle lives, and it sits
                // behind the same manage_options bar as this page, so anyone
                // reading the panel can act on it.
                'url' => admin_url('admin.php?page=nibwp-skills'),
            ];
        } elseif ($skill_enabled === true) {
            // The skill is on and the handoff still is not registered. Saying
            // "enable the skill" here would send somebody to flip a switch that
            // is already flipped, so this reports the mismatch instead.
            $state = 'blocked';
            $reason = sprintf(
                /* translators: 1: builder name, 2: ability name */
                __('%1$s is on this site and its NibWP skill is enabled, but the handoff ability %2$s is not registered here, so a design cannot be passed to it.', 'nibwp'),
                $label,
                $ability
            );
        } elseif ($skill !== null) {
            $state = 'blocked';
            $reason = sprintf(
                /* translators: %s: builder name */
                __('%s is on this site, but whether its NibWP skill is enabled cannot be read from here, so no handoff is claimed.', 'nibwp'),
                $label
            );
        } elseif ($ability !== '') {
            $state = 'blocked';
            $reason = sprintf(
                /* translators: 1: builder name, 2: ability name */
                __('%1$s is on this site, but no NibWP skill here provides the %2$s handoff, so a design can only be built as core blocks.', 'nibwp'),
                $label,
                $ability
            );
        } else {
            $state = 'blocked';
            $reason = sprintf(
                /* translators: %s: builder name */
                __('%s is on this site, but this bridge has no handoff for it, so a design can only be built as core blocks.', 'nibwp'),
                $label
            );
        }

        $rows[] = [
            'builder' => $slug,
            'label' => $label,
            'installed' => $installed,
            'ready' => $is_ready,
            'state' => $state,
            'skill' => $skill,
            'skill_enabled' => $skill_enabled,
            'handoff_ability' => $ability === '' ? null : $ability,
            'blocked_reason' => $reason,
            'fix' => $fix,
        ];
    }

    // Usable first, then one switch away, then stuck, then absent. Equal ranks
    // keep the catalogue order above, which PHP 8 sorts stably.
    $rank = ['ready' => 0, 'installed' => 1, 'blocked' => 2, 'missing' => 3];
    usort($rows, static fn (array $a, array $b): int => $rank[$a['state']] <=> $rank[$b['state']]);

    $recommended = strtolower(nibwp_paper_str($detect['recommended'] ?? '', 40));
    if ($recommended === '' || !in_array($recommended, array_column($rows, 'builder'), true)) {
        // The recommendation has to be one of the cards. If the shared detection
        // names something this panel is not showing, core blocks is the one
        // target that cannot be wrong.
        $recommended = 'gutenberg';
    }

    return [
        'builders' => $rows,
        'recommended' => $recommended,
        'note' => __('Core blocks are always available. A page builder needs its own NibWP skill enabled before a design can be handed to it.', 'nibwp'),
    ];
}

/* ───────────────────────────── ingest ───────────────────────────── */

/**
 * The core action: take what the agent read out of Paper, normalise it, store it.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_paper_ingest(array $input)
{
    $prepared = NIBWP_Paper_Ingest::prepare($input['design'] ?? null);
    if ($prepared instanceof WP_Error) {
        return $prepared;
    }

    $normalizer = new NIBWP_Paper_Normalize($prepared['assets'], $prepared['warnings']);
    $ndo = $normalizer->document($prepared['tree'], $prepared['tokens'], $prepared['name']);

    $entry = NIBWP_Paper_Library::save_design($prepared, $ndo);
    if ($entry instanceof WP_Error) {
        return $entry;
    }

    return [
        'ok' => true,
        'design_id' => $entry['id'],
        'handle' => '@paper/' . $entry['handle'],
        'name' => $entry['name'],
        'source' => $prepared['source'],
        'stats' => $entry['stats'],
        'tokens' => $ndo['tokens'],
        'fonts' => $entry['fonts'],
        'assets' => $entry['assets'],
        'warnings' => $ndo['warnings'],
        'next' => __('preview_convert { design_id } to see the markup, then convert with dry_run:false to write it.', 'nibwp'),
    ];
}

/**
 * The same bounding and sanitising as ingest, storing nothing.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_paper_validate_payload(array $input)
{
    $prepared = NIBWP_Paper_Ingest::prepare($input['design'] ?? null);
    if ($prepared instanceof WP_Error) {
        return $prepared;
    }

    return [
        'ok' => true,
        'stored' => false,
        'name' => $prepared['name'],
        'source' => $prepared['source'],
        'stats' => $prepared['stats'],
        'warnings' => $prepared['warnings'],
        'limits' => NIBWP_Paper_Ingest::limits(),
    ];
}

/* ───────────────────────────── library ───────────────────────────── */

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function nibwp_paper_list_designs(array $input): array
{
    $limit = nibwp_paper_limit($input['limit'] ?? null);
    $offset = nibwp_paper_offset($input['offset'] ?? null);
    $all = NIBWP_Paper_Library::all();
    $total = count($all);

    $rows = [];
    foreach (array_slice(array_reverse($all, true), $offset, $limit, true) as $entry) {
        $rows[] = NIBWP_Paper_Library::card((array) $entry);
    }

    return [
        'designs' => $rows,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
    ];
}

/**
 * Resolve the design a caller named, with a refusal that says what IS there.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_paper_resolve_design(mixed $ref)
{
    $ref = nibwp_paper_str($ref, 120);
    if ($ref === '') {
        return nibwp_paper_err('missing_design', __('Name the design as design_id — its id, its @paper/handle, or its name.', 'nibwp'), 422);
    }

    $entry = NIBWP_Paper_Library::resolve($ref);
    if ($entry === null) {
        $known = array_slice(NIBWP_Paper_Library::known_handles(), 0, 20);

        return nibwp_paper_err(
            'unknown_design',
            sprintf(
                /* translators: 1: what was asked for, 2: handles that do exist */
                __('No design matches "%1$s". In the library: %2$s', 'nibwp'),
                $ref,
                $known === [] ? __('nothing yet — ingest one first.', 'nibwp') : implode(', ', $known)
            ),
            404
        );
    }

    return $entry;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_paper_get_design(array $input)
{
    $entry = nibwp_paper_resolve_design($input['design_id'] ?? null);
    if ($entry instanceof WP_Error) {
        return $entry;
    }

    $card = NIBWP_Paper_Library::card($entry);
    $card['tokens'] = (array) ($entry['tokens'] ?? []);
    $card['fonts'] = array_slice((array) ($entry['fonts'] ?? []), 0, NIBWP_PAPER_MAX_ROWS);
    $card['assets'] = array_slice((array) ($entry['assets'] ?? []), 0, NIBWP_PAPER_MAX_ROWS);
    $card['warnings'] = array_slice((array) ($entry['warnings'] ?? []), 0, NIBWP_PAPER_MAX_ROWS);
    $card['conversions'] = array_slice((array) ($entry['conversions'] ?? []), 0, NIBWP_PAPER_MAX_ROWS);

    return ['design' => $card];
}

/**
 * The normalized design object itself, for an agent that wants the structure.
 *
 * Bounded: a full tree can be megabytes, and the summary answers most questions.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_paper_get_ndo(array $input)
{
    $entry = nibwp_paper_resolve_design($input['design_id'] ?? null);
    if ($entry instanceof WP_Error) {
        return $entry;
    }

    $ndo = NIBWP_Paper_Library::ndo((string) $entry['id']);
    if ($ndo === null) {
        return nibwp_paper_err('no_ndo', __('That design has no stored structure. Ingest it again.', 'nibwp'), 404);
    }

    return [
        'design_id' => (string) $entry['id'],
        'summary' => nibwp_paper_ndo_summary($ndo),
        'ndo' => $ndo,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_paper_rename_design(array $input)
{
    $entry = nibwp_paper_resolve_design($input['design_id'] ?? null);
    if ($entry instanceof WP_Error) {
        return $entry;
    }

    $name = nibwp_paper_str($input['name'] ?? null, 80);
    if ($name === '') {
        return nibwp_paper_err('missing_name', __('Give the new label as name.', 'nibwp'), 422);
    }

    $updated = NIBWP_Paper_Library::rename((string) $entry['id'], sanitize_text_field($name));
    if ($updated === null) {
        return nibwp_paper_err('unknown_design', __('That design is no longer in the library.', 'nibwp'), 404);
    }

    return [
        'ok' => true,
        'design_id' => (string) $updated['id'],
        'name' => (string) $updated['name'],
        'handle' => '@paper/' . (string) $updated['handle'],
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_paper_delete_design(array $input)
{
    $entry = nibwp_paper_resolve_design($input['design_id'] ?? null);
    if ($entry instanceof WP_Error) {
        return $entry;
    }

    $id = (string) $entry['id'];
    NIBWP_Paper_Library::delete($id);

    return [
        'ok' => true,
        'deleted' => $id,
        // Pages built from it are left alone on purpose: deleting a design is
        // tidying the library, not unpublishing somebody's page.
        'note' => __('The design is gone from the library. Pages already built from it are untouched.', 'nibwp'),
    ];
}

/* ───────────────────────────── convert ───────────────────────────── */

/**
 * Structure counts for a response that does not carry the whole tree.
 *
 * @param array<string, mixed> $ndo
 * @return array<string, mixed>
 */
function nibwp_paper_ndo_summary(array $ndo): array
{
    if (function_exists('nibwp_figma_ndo_summary')) {
        return nibwp_figma_ndo_summary($ndo);
    }

    $counts = ['section' => 0, 'container' => 0, 'text' => 0, 'shape' => 0, 'component' => 0, 'component_instance' => 0];
    $sections = [];
    $walk = static function (array $node, callable $walk) use (&$counts, &$sections): void {
        $type = (string) ($node['type'] ?? 'container');
        if (isset($counts[$type])) {
            $counts[$type]++;
        }
        if ($type === 'section' && ($node['name'] ?? '') !== '') {
            $sections[] = (string) $node['name'];
        }
        foreach ((array) ($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $walk($child, $walk);
            }
        }
    };
    if (is_array($ndo['root'] ?? null)) {
        $walk($ndo['root'], $walk);
    }

    return [
        'title' => (string) ($ndo['target']['title'] ?? ''),
        'counts' => $counts,
        'top_sections' => array_slice($sections, 0, 20),
        'warnings' => array_values((array) ($ndo['warnings'] ?? [])),
    ];
}

/**
 * The shared convert pipeline: stored NDO → the chosen builder → (a page).
 *
 * The emitters are the Figma ones on purpose. The NDO is the contract; growing a
 * second set of emitters here would mean two places to fix every layout bug.
 *
 * @param array<string, mixed> $entry
 * @return array<string, mixed>|WP_Error
 */
function nibwp_paper_do_convert(array $entry, string $builder, string $title, bool $dry_run, int $post_id = 0)
{
    if (!nibwp_paper_shared_emitters_loaded()) {
        return nibwp_paper_err('no_pipeline', __('The shared emitters are not installed, so this design cannot be converted here.', 'nibwp'), 500);
    }

    $ndo = NIBWP_Paper_Library::ndo((string) $entry['id']);
    if ($ndo === null) {
        return nibwp_paper_err('no_ndo', __('That design has no stored structure. Ingest it again.', 'nibwp'), 404);
    }

    if ($title === '') {
        $title = nibwp_paper_str($ndo['target']['title'] ?? $entry['name'] ?? '', 180);
    }
    if ($title === '') {
        $title = __('Paper design', 'nibwp');
    }

    $detect = nibwp_paper_detect_builders();
    $target = nibwp_paper_resolve_builder($builder, (array) $detect['active_builders']);
    $map = nibwp_paper_builder_ability_map();
    $ability = $map[$target] ?? '';
    $ability_ready = $ability !== '' && function_exists('nibwp_has_ability') && nibwp_has_ability($ability);
    $summary = nibwp_paper_ndo_summary($ndo);

    // A real builder is present and its skill is on → the neutral HTML goes to
    // that skill's own ability. We do not fake-execute another skill's pipeline,
    // and we never hand-roll its markup.
    if ($target !== 'gutenberg' && $ability_ready) {
        return [
            'ok' => true,
            'mode' => 'handoff',
            'dry_run' => $dry_run,
            'design_id' => (string) $entry['id'],
            'target_builder' => $target,
            'next_ability' => $ability,
            'title' => $title,
            'html' => (new NIBWP_Figma_Html())->render($ndo),
            'tokens_css' => nibwp_paper_str($ndo['tokens']['css'] ?? '', 20000),
            'summary' => $summary,
            'note' => sprintf(
                /* translators: 1: ability name, 2: skill id */
                __('Do not pass this html to %1$s directly — it validates and persists a native payload, it does not convert. Read its rules with nibwp/load-skill-playbook { skill_id: "%2$s" }, synthesize the native payload from the html and tokens_css, submit with dry_run:true, fix every failure, then commit. Or convert with builder:"gutenberg" to write core blocks straight away.', 'nibwp'),
                $ability,
                $target . '-pro'
            ),
        ];
    }

    $markup = (new NIBWP_Figma_Gutenberg())->render($ndo);
    if (trim($markup) === '') {
        return nibwp_paper_err('empty_conversion', __('Conversion produced no content — the design has no visible nodes.', 'nibwp'), 422);
    }

    if ($target !== 'gutenberg' && !$ability_ready) {
        $summary['warnings'][] = sprintf(
            /* translators: %s: builder name */
            __('No enabled NibWP skill for "%s", so this was built as core blocks.', 'nibwp'),
            $target
        );
    }

    if ($dry_run) {
        return [
            'ok' => true,
            'dry_run' => true,
            'wrote_nothing' => true,
            'design_id' => (string) $entry['id'],
            'target_builder' => 'gutenberg',
            'title' => $title,
            'post_id' => $post_id > 0 ? $post_id : null,
            'markup' => $markup,
            'summary' => $summary,
            'next' => __('Send the same call with dry_run:false to write it.', 'nibwp'),
        ];
    }

    if ($post_id > 0) {
        $result = wp_update_post([
            'ID' => $post_id,
            // wp_insert_post() unslashes and core runs content_save_pre, so the
            // markup is slashed once here and nothing else is applied to it.
            // Block delimiters carry - escapes that an unslash would eat.
            'post_content' => wp_slash($markup),
        ], true);
        if (is_wp_error($result)) {
            return $result;
        }
        $written = (int) $post_id;
    } else {
        $result = wp_insert_post([
            'post_title' => $title,
            'post_content' => wp_slash($markup),
            'post_status' => 'draft',
            'post_type' => 'page',
        ], true);
        if (is_wp_error($result)) {
            return $result;
        }
        $written = (int) $result;
    }

    NIBWP_Paper_Library::record_conversion((string) $entry['id'], $written, 'gutenberg', $title);

    return [
        'ok' => true,
        'dry_run' => false,
        'design_id' => (string) $entry['id'],
        'post_id' => $written,
        'title' => $title,
        'builder' => 'gutenberg',
        'edit_url' => (string) get_edit_post_link($written, 'url'),
        'preview_url' => (string) get_permalink($written),
        'summary' => $summary,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_paper_preview_convert(array $input)
{
    $entry = nibwp_paper_resolve_design($input['design_id'] ?? null);
    if ($entry instanceof WP_Error) {
        return $entry;
    }

    return nibwp_paper_do_convert(
        $entry,
        nibwp_paper_str($input['builder'] ?? 'auto', 40),
        nibwp_paper_str($input['title'] ?? '', 180),
        true,
        0
    );
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_paper_convert(array $input)
{
    $entry = nibwp_paper_resolve_design($input['design_id'] ?? null);
    if ($entry instanceof WP_Error) {
        return $entry;
    }

    // Default to dry-run. Writing a page is the destructive half, so it has to be
    // asked for explicitly — the same bargain every builder ability here strikes.
    $dry_run = !array_key_exists('dry_run', $input) || (bool) $input['dry_run'];

    $post_id = isset($input['post_id']) && is_numeric($input['post_id']) ? (int) $input['post_id'] : 0;
    if ($post_id > 0) {
        // Replacing an existing page is authority over THAT page, which the hub's
        // edit_posts check does not establish.
        if (!current_user_can('edit_post', $post_id)) {
            return nibwp_paper_forbidden(
                sprintf(
                    /* translators: %d: post id */
                    __('Replacing the content of page %d', 'nibwp'),
                    $post_id
                ),
                'edit_post'
            );
        }
        if (get_post_status($post_id) === false) {
            return nibwp_paper_err('unknown_post', __('That post does not exist.', 'nibwp'), 404);
        }
    }

    return nibwp_paper_do_convert(
        $entry,
        nibwp_paper_str($input['builder'] ?? 'auto', 40),
        nibwp_paper_str($input['title'] ?? '', 180),
        $dry_run,
        $post_id
    );
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_paper_list_conversions(array $input)
{
    $limit = nibwp_paper_limit($input['limit'] ?? null);
    $offset = nibwp_paper_offset($input['offset'] ?? null);
    $ref = nibwp_paper_str($input['design_id'] ?? null, 120);

    $rows = [];
    $designs = NIBWP_Paper_Library::all();
    if ($ref !== '') {
        $entry = nibwp_paper_resolve_design($ref);
        if ($entry instanceof WP_Error) {
            return $entry;
        }
        $designs = [(string) $entry['id'] => $entry];
    }

    foreach ($designs as $entry) {
        foreach ((array) (((array) $entry)['conversions'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $post_id = (int) ($row['post_id'] ?? 0);
            $rows[] = [
                'design_id' => (string) (((array) $entry)['id'] ?? ''),
                'design_name' => nibwp_paper_str(((array) $entry)['name'] ?? '', 120),
                'post_id' => $post_id,
                'post_title' => nibwp_paper_str($row['title'] ?? '', 180),
                'builder' => nibwp_paper_str($row['builder'] ?? '', 40),
                'converted_at' => (int) ($row['at'] ?? 0),
                'post_exists' => $post_id > 0 && get_post_status($post_id) !== false,
            ];
            if (count($rows) >= $offset + $limit) {
                break 2;
            }
        }
    }

    return [
        'conversions' => array_slice($rows, $offset, $limit),
        'limit' => $limit,
        'offset' => $offset,
    ];
}

/* ───────────────────────────── tokens + fonts ───────────────────────────── */

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_paper_list_tokens(array $input)
{
    $entry = nibwp_paper_resolve_design($input['design_id'] ?? null);
    if ($entry instanceof WP_Error) {
        return $entry;
    }

    $tokens = (array) ($entry['tokens'] ?? []);

    return [
        'design_id' => (string) $entry['id'],
        'tokens' => [
            'source' => nibwp_paper_str($tokens['source'] ?? 'paper', 40),
            'colors' => array_slice((array) ($tokens['colors'] ?? []), 0, NIBWP_PAPER_MAX_ROWS, true),
            'text' => array_slice((array) ($tokens['text'] ?? []), 0, NIBWP_PAPER_MAX_ROWS, true),
            'css' => nibwp_paper_str($tokens['css'] ?? '', 20000),
        ],
        'targets' => NIBWP_Paper_Tokens::targets(),
    ];
}

/** @return array<string, mixed> */
function nibwp_paper_token_targets(): array
{
    return [
        'targets' => NIBWP_Paper_Tokens::targets(),
        'note' => __('sync_tokens writes into one of these. If none is present it refuses rather than inventing a place to put them.', 'nibwp'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_paper_sync_tokens(array $input)
{
    $entry = nibwp_paper_resolve_design($input['design_id'] ?? null);
    if ($entry instanceof WP_Error) {
        return $entry;
    }

    $tokens = is_array($input['tokens'] ?? null) ? (array) $input['tokens'] : (array) ($entry['tokens'] ?? []);
    $target = nibwp_paper_str($input['target'] ?? '', 40);
    $dry_run = array_key_exists('dry_run', $input) ? (bool) $input['dry_run'] : false;

    return NIBWP_Paper_Tokens::sync($tokens, $target, $dry_run, (string) $entry['id']);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_paper_list_fonts(array $input)
{
    $entry = nibwp_paper_resolve_design($input['design_id'] ?? null);
    if ($entry instanceof WP_Error) {
        return $entry;
    }

    $fonts = array_slice((array) ($entry['fonts'] ?? []), 0, NIBWP_PAPER_MAX_ROWS);

    return [
        'design_id' => (string) $entry['id'],
        'fonts' => $fonts,
        'note' => __('Paper reports the families the design uses. Whether this site can serve them is a theme question — nothing here installs a font.', 'nibwp'),
    ];
}

/* ───────────────────────────── assets ───────────────────────────── */

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_paper_attach_asset(array $input)
{
    $entry = null;
    if (nibwp_paper_str($input['design_id'] ?? null, 120) !== '') {
        $entry = nibwp_paper_resolve_design($input['design_id'] ?? null);
        if ($entry instanceof WP_Error) {
            return $entry;
        }
    }

    $result = NIBWP_Paper_Assets::attach(
        $input['url'] ?? null,
        $input['alt'] ?? null,
        $input['filename'] ?? null
    );
    if ($result instanceof WP_Error) {
        return $result;
    }

    $ref = nibwp_paper_str($input['ref'] ?? '', 160);
    if ($entry !== null) {
        NIBWP_Paper_Library::link_asset((string) $entry['id'], $ref, (int) $result['attachment_id'], (string) $result['url']);
        $result['design_id'] = (string) $entry['id'];
        $result['ref'] = $ref;
    }

    return $result;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_paper_list_assets(array $input)
{
    $limit = nibwp_paper_limit($input['limit'] ?? null);
    $offset = nibwp_paper_offset($input['offset'] ?? null);

    $ref = nibwp_paper_str($input['design_id'] ?? null, 120);
    if ($ref !== '') {
        $entry = nibwp_paper_resolve_design($ref);
        if ($entry instanceof WP_Error) {
            return $entry;
        }
        $rows = array_slice(array_values((array) ($entry['assets'] ?? [])), $offset, $limit);

        return ['assets' => $rows, 'design_id' => (string) $entry['id'], 'limit' => $limit, 'offset' => $offset];
    }

    $rows = [];
    foreach (NIBWP_Paper_Library::all() as $entry) {
        foreach ((array) (((array) $entry)['assets'] ?? []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $asset['design_id'] = (string) (((array) $entry)['id'] ?? '');
            $rows[] = $asset;
        }
    }

    return [
        'assets' => array_slice($rows, $offset, $limit),
        'total' => count($rows),
        'limit' => $limit,
        'offset' => $offset,
    ];
}

/* ───────────────────────────── audit ───────────────────────────── */

/**
 * What was ingested and never used, and where the library and the site disagree.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function nibwp_paper_audit(array $input): array
{
    $limit = nibwp_paper_limit($input['limit'] ?? null);

    $never_converted = [];
    $stale_pages = [];
    $missing_assets = [];
    $unmatched_tokens = [];

    $site_colors = NIBWP_Paper_Tokens::site_colors();

    foreach (NIBWP_Paper_Library::all() as $entry) {
        $entry = (array) $entry;
        $id = (string) ($entry['id'] ?? '');
        $name = nibwp_paper_str($entry['name'] ?? '', 120);
        $conversions = (array) ($entry['conversions'] ?? []);

        if ($conversions === []) {
            if (count($never_converted) < $limit) {
                $never_converted[] = [
                    'design_id' => $id,
                    'name' => $name,
                    'ingested_at' => (int) ($entry['created'] ?? 0),
                ];
            }
        }

        $updated = (int) ($entry['updated'] ?? 0);
        foreach ($conversions as $row) {
            if (!is_array($row)) {
                continue;
            }
            $at = (int) ($row['at'] ?? 0);
            $post_id = (int) ($row['post_id'] ?? 0);
            // Re-ingested after the page was built → the page is behind the design.
            if ($updated > $at && count($stale_pages) < $limit) {
                $stale_pages[] = [
                    'design_id' => $id,
                    'name' => $name,
                    'post_id' => $post_id,
                    'converted_at' => $at,
                    'design_updated_at' => $updated,
                    'post_exists' => $post_id > 0 && get_post_status($post_id) !== false,
                ];
            }
        }

        foreach ((array) ($entry['assets'] ?? []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $attachment = (int) ($asset['attachment_id'] ?? 0);
            $present = $attachment > 0 && get_post_status($attachment) !== false;
            if (!$present && count($missing_assets) < $limit) {
                $missing_assets[] = [
                    'design_id' => $id,
                    'ref' => nibwp_paper_str($asset['ref'] ?? '', 160),
                    'source_url' => nibwp_paper_str($asset['source_url'] ?? '', 500),
                    'attachment_id' => $attachment,
                    'reason' => $attachment > 0
                        ? __('The attachment was deleted from the Media Library.', 'nibwp')
                        : __('The design references this image but nothing was ever attached for it.', 'nibwp'),
                ];
            }
        }

        foreach ((array) (((array) ($entry['tokens'] ?? []))['colors'] ?? []) as $token => $value) {
            $hex = strtolower(nibwp_paper_str($value, 40));
            if ($hex === '' || $site_colors === []) {
                continue;
            }
            if (!in_array($hex, $site_colors, true) && count($unmatched_tokens) < $limit) {
                $unmatched_tokens[] = [
                    'design_id' => $id,
                    'token' => nibwp_paper_str($token, 80),
                    'value' => $hex,
                ];
            }
        }
    }

    return [
        'never_converted' => $never_converted,
        'stale_pages' => $stale_pages,
        'missing_assets' => $missing_assets,
        'unmatched_tokens' => $unmatched_tokens,
        'site_palette_known' => $site_colors !== [],
        'limit' => $limit,
        'note' => $site_colors === []
            ? __('This site exposes no palette we can compare against, so unmatched_tokens is empty rather than wrong.', 'nibwp')
            : '',
    ];
}
