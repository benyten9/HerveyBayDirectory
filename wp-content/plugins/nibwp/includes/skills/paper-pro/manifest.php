<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Skill Manifest — Paper Pro.
 *
 * A Paper design becomes a native WordPress page — and the way it gets here is
 * the whole point of this skill.
 *
 * figma-pro is a PULL skill. WordPress holds a token, calls a cloud API, and
 * fetches the file on its own. Nothing about that works for Paper, and no amount
 * of wishing changes it: Paper's MCP server is started by the Paper Desktop app
 * and is local only — stdio through the `paper mcp` CLI, with a legacy HTTP
 * endpoint bound to 127.0.0.1:29979 — there is no API key, no OAuth, and no cloud
 * REST API for Paper files at all (paper.design/docs/mcp, read 2 Oct 2026). A
 * WordPress server, which is somewhere else entirely, cannot see the designer's
 * canvas. Its own localhost is not the designer's localhost.
 *
 * So Paper Pro is a HANDOVER skill. The agent is the bridge. It is running on the
 * machine where Paper is open, with Paper's MCP connected locally and NibWP's MCP
 * connected to the site; it reads the design out of Paper using Paper's own tools,
 * and hands the result to WordPress. There are no credentials anywhere in this
 * path, and nothing on the WordPress side can verify the file independently —
 * which is exactly why this skill exists. It is the thing that makes the handover
 * honest: it plans the conversion before anything is written, validates what came
 * out of Paper against the NDO contract, delegates every write to the Paper
 * integration, and reads the page back afterwards to say where it drifted.
 *
 * Two consequences worth stating plainly, because a user choosing between the two
 * skills deserves them:
 *   - Paper Pro cannot run headless. No cron job, no "convert this nightly", no
 *     server-side import. If the designer's machine is not in the loop, there is
 *     no design.
 *   - What validation can catch is shape, not fidelity. A handover that silently
 *     dropped half a frame is still a well-formed NDO. So the skill counts what
 *     arrived, says what it counted, and refuses to describe a thin tree as a
 *     finished page.
 *
 * This skill reaches Paper never. Not over the network, not over a shell. It tells
 * the agent which Paper tools to call and then checks what comes back —
 * tests/skills/paper-pro-check.php parses these sources and fails if an HTTP call,
 * a cURL call or a process call appears in any of them.
 *
 * Persistence belongs to the Paper integration (includes/integrations/paper/),
 * which registers one hub, nibwp/paper-manage. This skill validates, plans,
 * recommends and orchestrates; it writes nothing. No $wpdb, no wp_insert_post, no
 * update_post_meta anywhere in these files — asserted over PHP tokens by the same
 * check, because these files discuss all three in their comments and a text scan
 * would fail on the documentation of the rule.
 */

return [
    'id'             => 'paper-pro',
    'name'           => 'Paper Pro',
    'tagline'        => 'Hand a Paper design over to WordPress and get a native page — the agent reads the canvas locally, the skill validates every node before anything is written',
    'description'    => 'Open a design in Paper, ask for it in WordPress, and the agent carries it across. Paper\'s MCP server is local to the Desktop app — no API key, no cloud API for files — so WordPress cannot fetch a Paper file the way it fetches a Figma one, and this skill does not pretend otherwise. Instead the agent reads the canvas with Paper\'s own tools on the machine where Paper is open, and Paper Pro checks the handover: every node validated against the NDO contract WordPress already understands, so a truncated or mis-shaped tree is a message naming the node rather than a page with an empty section in it. Before anything is written it plans — which builder to target and why, which repeated cards should become one loop or one component instead of nine copies, which text is real copy and which is placeholder, which colours and type sizes already have a token on this site, what the responsive story is, and what it cannot do. Conversion previews first and defaults to a dry run. Afterwards a named part of the page can be changed without rebuilding the rest, and the finished page can be read back and compared to the design it came from. Every write goes through the Paper integration; this skill never touches the database, and never tries to reach Paper itself.',
    'vendor'         => 'NIBWP',
    'version'        => '1.0.0',
    'category'       => 'design',
    'premium'        => true,
    'price'          => 49,
    'required_plans' => ['pro', 'agency', 'lifetime'],
    'requires'       => ['paper'],
    'entitlements'   => ['skill:paper', 'skill:paper-pro', 'integration:paper'],
    'integration_files' => ['paper'],
    'features'       => [
        'A design open in Paper becomes a native page in your site\'s own builder',
        'The agent reads the canvas locally with Paper\'s own MCP tools — no credentials, no cloud API, nothing to connect on the WordPress side',
        'Every handed-over node validated against the NDO contract before a page exists — a bad tree names the node, not the symptom',
        'Counts what arrived: frames, text, images. A handover that lost half the design is said out loud, not converted quietly',
        'Plans before it builds: target builder, repeats worth looping, placeholder copy, token matches, responsive story, and the parts it cannot do',
        'Repeated cards detected and reported as one loop or one component — never nine duplicated subtrees',
        'Literal colours and type sizes matched against the tokens this site already defines, by value',
        'Refuses clamp() for font-size, because the text sizes here are tokens and the validator rejects it',
        'Dry run by default — the preview tells you what would land before anything lands',
        'Change one named part of a converted page and leave the rest of it alone',
        'Read the finished page back and report where it drifted from the design, with the integration\'s own audit findings',
        'Writes nothing itself — every write goes through the Paper integration',
    ],
    'ability_files' => [
        'abilities/plan.php',
        'abilities/convert.php',
        'abilities/refine.php',
        'abilities/audit.php',
    ],
    'instructions_file' => 'authoring/SKILL.md',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3h9l5 5v13H5z"/><path d="M14 3v5h5"/><path d="M8.5 12.5h7"/><path d="M8.5 16.5h4.5"/></svg>',

    // ─── v2 routing contract ──────────────────────────────────────────────
    // The regexes are a fast path. This sentence is the boundary.
    'use_when' => 'The user wants something that lives in Paper built, rebuilt or converted in WordPress, or wants to know what a design already converted from Paper turned into. Owns it however it is phrased. The design cannot be fetched from WordPress — Paper\'s MCP server is local to the Desktop app and has no cloud API — so YOU read the canvas with Paper\'s own MCP tools first, normalise it into an NDO document, and hand the result to this skill. If Paper\'s MCP is not connected in this session, say that plainly instead of working from a screenshot.',

    'triggers' => [
        '/(?i)\bpaper\.design\b/',
        '/(?i)\bpaper\s+(?:design|file|document|canvas|artboard|frame|project)s?\b/',
        '/(?i)\b(?:convert|rebuild|port|turn|import|build|make|recreate)\b[^.\n]{0,40}\bpaper\b[^.\n]{0,40}\b(?:design|file|document|canvas|artboard|frame|layer|page)\b/',
        '/(?i)\bpaper\b[^.\n]{0,30}\b(?:to|into|as)\b[^.\n]{0,20}\b(?:wordpress|wp|etch|bricks|elementor|gutenberg|page|block|site)\b/',
        '/(?i)\bpaper\s+mcp\b/',
        '/(?i)\b(?:audit|check|review|compare)\b[^.\n]{0,30}\bpaper\b[^.\n]{0,30}\b(?:design|conversion|page)\b/',
    ],
    'commands' => [
        '/paper' => [
            'description' => 'Hand a design read out of Paper over to WordPress: plan it, validate it, convert it.',
        ],
        '/paper-plan' => [
            'description' => 'Plan the conversion before anything is written — builder, repeats, tokens, placeholder copy, responsive story, limits.',
        ],
        '/paper-convert' => [
            'description' => 'Validate the handover and convert it into a page through the Paper integration. Dry run by default.',
        ],
        '/paper-refine' => [
            'description' => 'Change named parts of an already-converted page without rebuilding the rest.',
        ],
        '/paper-audit' => [
            'description' => 'Read a converted page back and report where it drifted from the design.',
        ],
    ],
    'mandatory_routing' => [
        'preflight_required' => false,
        'pipeline' => [
            [
                'ability'       => 'nibwp/paper-manage',
                'args_template' => ['action' => 'status'],
                'why'           => 'What the WordPress side has: the integration live, the active builder, the designs already ingested. If this is unavailable there is nothing to hand the design to — say so rather than building the page another way.',
            ],
            [
                'ability'       => 'nibwp/paper-manage',
                'args_template' => ['action' => 'list_tokens'],
                'why'           => 'The colours and type sizes this site already defines. Read these BEFORE planning, so the plan can match literals to tokens instead of pasting hex codes into a page that has a palette.',
            ],
            [
                'ability'       => 'nibwp/paper-pro-plan',
                'args_template' => ['design' => '{the NDO you built from what Paper\'s MCP tools returned}', 'site_tokens' => '{from list_tokens}'],
                'why'           => 'The conversion plan, with nothing written: builder and the reason for it, repeats worth looping, placeholder copy, token matches, the responsive story, and what this conversion cannot do. Show it to the user before converting.',
            ],
            [
                'ability'       => 'nibwp/paper-pro-convert',
                'args_template' => ['design' => '{the same NDO, unchanged}', 'dry_run' => true],
                'why'           => 'Validation plus the integration\'s own preview. Nothing is written. Fix every error here — an error at this point is a node, after it is a page with a hole in it.',
            ],
            [
                'ability'       => 'nibwp/paper-pro-convert',
                'args_template' => ['design' => '{the same NDO, unchanged}', 'dry_run' => false],
                'why'           => 'Convert for real, through the integration, once the dry run is clean and the user has seen the plan.',
            ],
            [
                'ability'       => 'nibwp/paper-pro-audit',
                'args_template' => ['post_id' => '{the converted page}', 'design' => '{the same NDO}'],
                'why'           => 'Read the page back and report drift. A write that returned success is not the same as a page that matches the design.',
            ],
        ],
        'forbidden_actions' => [
            'Asking WordPress to fetch the Paper file — there is no cloud API for Paper files and no token to use; the server\'s own localhost is not the designer\'s machine',
            'Calling 127.0.0.1:29979 or the paper CLI from inside WordPress — that endpoint exists only on the machine running Paper Desktop',
            'Recreating the design from a screenshot while Paper\'s MCP is connected and could hand over the real tree',
            'Writing the page with nibwp/wp-create-post, raw post_content or builder meta instead of the integration\'s convert action',
            'Renaming the NDO keys — figma_type is named for the tool that defined the format first and every builder emitter reads it',
            'Pasting literal hex and px values that list_tokens shows a token for',
            'clamp() for font-size — the text sizes are tokens here and the validator rejects it',
            'Duplicating a repeated card instead of one loop or one component with dynamic data',
            'Converting with dry_run false before a clean dry run',
            'Describing a design as converted when the handover arrived with fewer nodes than the canvas has — count it and say so',
        ],
        'before_answering' => 'You are the bridge. Paper cannot be reached from WordPress, so read the design out of Paper yourself with Paper\'s own MCP tools — on this machine, where Paper Desktop is running — and normalise it into an NDO 1.0 document before you call anything here. Then read the site\'s tokens, plan, dry-run, convert, and read the page back. If Paper\'s MCP is not connected in this session, say that and stop: a screenshot is not the design, and this skill will not pretend one is.',
    ],
    'preflight_questions' => [
        [
            'key'       => 'paper_source',
            'prompt'    => 'Which Paper design is being converted — the document and frame you read with Paper\'s MCP tools, named so the conversion can be traced back to it.',
            'type'      => 'string',
            'required'  => true,
            'cache_key' => 'paper_source',
            // Per conversion, not per site. A remembered answer labels the next
            // design with the last one's name, which is how an audit ends up
            // comparing a page to a design it was never built from.
            'cache'     => false,
        ],
        [
            'key'       => 'paper_builder',
            'prompt'    => 'Target builder — auto (use whatever this site already builds with), or force etchwp / bricks / elementor / gutenberg.',
            'choices'   => ['auto', 'etchwp', 'bricks', 'elementor', 'gutenberg'],
            'type'      => 'enum',
            'required'  => false,
            'cache_key' => 'paper_builder',
        ],
        [
            'key'       => 'paper_push_mode',
            'prompt'    => 'Land it as a new_page, a new_post, or an update to something that exists?',
            'choices'   => ['new_page', 'new_post', 'update'],
            'type'      => 'enum',
            'required'  => false,
            'cache_key' => 'paper_push_mode',
        ],
        [
            'key'            => 'paper_target_post_id',
            'prompt'         => 'Which existing post or page should be updated?',
            'type'           => 'integer',
            'conditional_on' => ['paper_push_mode' => 'update'],
            'cache_key'      => 'paper_target_post_id',
            'cache'          => false,
        ],
        [
            'key'       => 'paper_sync_tokens',
            'prompt'    => 'Sync the design\'s colours and type sizes into the site\'s token system as part of this conversion? It changes every page, so it is off unless you say yes.',
            'choices'   => ['no', 'yes'],
            'type'      => 'enum',
            'required'  => false,
            'cache_key' => 'paper_sync_tokens',
        ],
    ],
];
