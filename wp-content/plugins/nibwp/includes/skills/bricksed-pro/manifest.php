<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Skill Manifest — Bricksed Pro
 *
 * The library half of Bricks work. bricks-pro builds sections onto a page;
 * this one puts them in the Bricksed library, takes them back out as native
 * Bricks elements, and keeps them portable enough to be worth storing.
 */

return [
    'id'             => 'bricksed-pro',
    'name'           => 'Bricksed Pro',
    'tagline'        => 'Create, organise and reuse your Bricksed component library for Bricks through an agent',
    'description'    => 'Build a section in Bricks, save it into a Bricksed folder — straight off the page, with its global classes and Bricks components — rename it, move it, and place it on any page as native Bricks elements with its component properties overridden. Every save is checked twice: once for how it is built — ACSS tokens, BEM, native elements — and once for whether it will still work on a different site.',
    'vendor'         => 'NIBWP',
    'version'        => '1.0.0',
    'category'       => 'page-builders',
    'premium'        => true,
    'price'          => 49,
    'required_plans' => ['pro', 'agency', 'lifetime'],
    'requires'       => ['bricksed'],
    // The abilities run the bricks-pro skill's validator. Not an integration,
    // so it cannot go in `requires`.
    'requires_skills' => ['bricks-pro'],
    'entitlements'   => ['skill:bricksed', 'skill:bricksed-pro', 'integration:bricksed'],
    // bricks and automaticcss travel with it: placements write Bricks data,
    // and every build rule it enforces is an ACSS token rule.
    'integration_files' => ['bricksed', 'bricks', 'automaticcss'],
    'features'       => [
        'Save any section built in Bricks straight into a Bricksed folder, with its global classes and Bricks components',
        'Rename and move components between folders by asking',
        'Place a stored component on any page as native Bricks elements, with its properties overridden per placement',
        'Follows Bricksed\'s own insert rules: your existing classes and components are never overwritten',
        'Portability checks — nothing with this site\'s attachment ids, post ids or upload URLs gets stored',
        'Component properties with defaults, so a section is reusable rather than a snapshot',
        'ACSS tokens with fallbacks and BEM brand prefixes enforced on every save',
        'Dry-run validation so you see every warning before anything is written',
        'Duplicate any component to a new name or folder without touching the original',
        'Audit the whole library against today\'s rules and see exactly what to fix',
    ],
    'ability_files' => [
        'abilities/save-component.php',
        'abilities/reuse-component.php',
        'abilities/audit-library.php',
    ],
    'instructions_file' => 'authoring/SKILL.md',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M17.5 14v7M14 17.5h7"/></svg>',

    'use_when' => 'The user wants something put into, taken out of, or reorganised within the Bricksed library: saving a Bricks section or component, renaming one, moving it between folders, deleting one, browsing what is stored, or placing a stored component on a page. Building a section from scratch in Bricks is bricks-pro\'s job; this skill owns it only once the library is involved.',

    'triggers' => [
        '/(?i)\bbricksed\b[^.\n]{0,60}\b(?:librar|folder|save|store|reuse|rename|move|organis|organiz|catalog|place|insert|audit)/',
        '/(?i)\b(?:librar|folder|save|store|reuse|rename|move|organis|organiz|place|insert|audit)[a-z]*\b[^.\n]{0,60}\bbricksed\b/',
        '/(?i)\b(?:add|put|push)\b[^.\n]{0,40}\bto\b[^.\n]{0,20}\b(?:the\s+)?bricksed\b/',
        '/(?i)\b(?:my|our|the)\s+bricksed\s+(?:librar|collection|components)/',
    ],

    'commands' => [
        '/bricksed-save' => ['description' => 'Validate a Bricks section and save it into a Bricksed folder.'],
        '/bricksed-reuse' => ['description' => 'Place a stored Bricksed component on a page, with property overrides.'],
        '/bricksed-browse' => ['description' => 'List or search the Bricksed library tree.'],
        '/bricksed-audit' => ['description' => 'Check stored components against the build and portability rules.'],
    ],

    'mandatory_routing' => [
        'preflight_required' => true,
        'preflight_ability'  => 'nibwp/skill-preflight',
        'pipeline' => [
            [
                'ability'       => 'nibwp/bricksed-manage',
                'args_template' => ['action' => 'list'],
                'why'           => 'Read the tree first. Component ids come from the manifest and cannot be guessed, and the existing folders decide where a new component belongs.',
            ],
            [
                'ability'       => 'nibwp/skill-preflight',
                'args_template' => ['skill_id' => 'bricksed-pro'],
                'why'           => 'Resolve ACSS presence and the brand prefix, shared with bricks-pro so the user answers once per site.',
            ],
            [
                'ability'       => 'nibwp/load-skill-playbook',
                'args_template' => ['skill_id' => 'bricksed-pro'],
                'why'           => 'Read SKILL.md, the portability rules and the folder conventions.',
            ],
            [
                'ability'       => 'nibwp/bricksed-pro-save-component',
                'args_template' => ['dry_run' => true],
                'why'           => 'Validate. Show the user every warning — especially properties without defaults — before writing.',
            ],
            [
                'ability'       => 'nibwp/bricksed-pro-save-component',
                'args_template' => ['dry_run' => false],
                'why'           => 'Write, once validation is clean and the user has seen the warnings.',
            ],
        ],
        'forbidden_actions' => [
            'Save a component carrying an attachment id, post id, template id or menu id from this site',
            'Save a component with an absolute wp-content URL in it',
            'Save a component whose elements use global classes or Bricks components it does not include',
            'Save a component with no properties when any of its text, images or links would differ between uses',
            'Write directly to the Bricksed data folder on disk',
            'Call Bricksed_REST_API static methods instead of the bricksed-manage ability',
            'Write _bricks_page_content_2 or the global classes option by hand to place a component',
            'Modify a stored component, or a site class or Bricks component, as a side effect of reusing it',
        ],
        'before_answering' => 'List the library before proposing a folder. Dry-run before saving. A component going into a library is going to a second site — if anything in it is true only of this one, fix that first.',
    ],

    'preflight_questions' => [
        [
            'key'       => 'brand_prefix',
            'prompt'    => 'BEM brand prefix? (every global class will be {prefix}-...)',
            'detect'    => 'shared(brand_prefix)|get_option(nibwp_bricks_brand)|scan(bricks_global_classes)',
            'type'      => 'string',
            'required'  => true,
            'cache_key' => 'brand_prefix',
        ],
    ],
];
