<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Skill Manifest — Etchedy Pro
 *
 * The library half of Etch work. etchwp-pro builds components onto a page;
 * this one puts them in the library, takes them back out, and keeps them
 * portable enough to be worth storing.
 */

return [
    'id'             => 'etchedy-pro',
    'name'           => 'Etchedy Pro',
    'tagline'        => 'Create, organise and reuse your Etchedy component library through an agent',
    'description'    => 'Build a component, save it into an Etchedy folder, rename it, move it, and pull it back onto any page with its properties overridden. Every save is checked twice: once for how it is built — ACSS tokens, BEM, responsive, accessible — and once for whether it will still work on a different site.',
    'vendor'         => 'NIBWP',
    'version'        => '1.0.0',
    'category'       => 'page-builders',
    'premium'        => true,
    'price'          => 49,
    'required_plans' => ['pro', 'agency', 'lifetime'],
    'requires'       => ['etchedy'],
    // The abilities run the etchwp-pro skill's validator and persister. Not an
    // integration, so it cannot go in `requires`.
    'requires_skills' => ['etchwp-pro'],
    'entitlements'   => ['skill:etchedy', 'skill:etchedy-pro', 'integration:etchedy'],
    // etchwp and automaticcss travel with it: the validator this skill runs
    // lives in the etchwp integration's skill, and every rule it enforces is
    // an ACSS token rule.
    'integration_files' => ['etchedy', 'etchwp', 'automaticcss'],
    'features'       => [
        'Save any built component straight into an Etchedy folder',
        'Rename and move components between folders by asking',
        'Reuse a stored component on any page, with its text and images overridden per placement',
        'Portability checks — nothing with this site\'s post ids or upload URLs gets stored',
        'Property schemas generated for you, so a component is reusable rather than a snapshot',
        'ACSS tokens with fallbacks enforced on every save',
        'Responsive and accessible by default, checked before writing',
        'Loops, conditions and custom-field bindings preserved and declared',
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
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M17.5 14v7M14 17.5h7"/></svg>',

    'use_when' => 'The user wants something put into, taken out of, or reorganised within the Etchedy library: saving a component, renaming one, moving it between folders, deleting one, browsing what is stored, or reusing a stored component on a page. Building a component from scratch onto a page is etchwp-pro\'s job; this skill owns it only once the library is involved.',

    'triggers' => [
        '/(?i)\betchedy\b[^.\n]{0,60}\b(?:librar|folder|save|store|reuse|rename|move|organis|organiz|catalog)/',
        '/(?i)\b(?:librar|folder|save|store|reuse|rename|move|organis|organiz)[a-z]*\b[^.\n]{0,60}\betchedy\b/',
        '/(?i)\b(?:add|put|push)\b[^.\n]{0,40}\bto\b[^.\n]{0,20}\b(?:the\s+)?etchedy\b/',
        '/(?i)\b(?:my|our|the)\s+etchedy\s+(?:librar|collection|components)/',
    ],

    'commands' => [
        '/etchedy-save' => ['description' => 'Validate a component and save it into an Etchedy folder.'],
        '/etchedy-reuse' => ['description' => 'Place a stored Etchedy component on a page, with property overrides.'],
        '/etchedy-browse' => ['description' => 'List or search the Etchedy library tree.'],
        '/etchedy-audit' => ['description' => 'Check stored components against the build and portability rules.'],
    ],

    'mandatory_routing' => [
        'preflight_required' => true,
        'preflight_ability'  => 'nibwp/skill-preflight',
        'pipeline' => [
            [
                'ability'       => 'nibwp/etchedy-manage',
                'args_template' => ['action' => 'list'],
                'why'           => 'Read the tree first. Component ids come from the manifest and cannot be guessed, and the existing folders decide where a new component belongs.',
            ],
            [
                'ability'       => 'nibwp/skill-preflight',
                'args_template' => ['skill_id' => 'etchedy-pro'],
                'why'           => 'Resolve ACSS presence and the brand prefix, shared with etchwp-pro so the user answers once per site.',
            ],
            [
                'ability'       => 'nibwp/load-skill-playbook',
                'args_template' => ['skill_id' => 'etchedy-pro'],
                'why'           => 'Read SKILL.md, the portability rules and the folder conventions.',
            ],
            [
                'ability'       => 'nibwp/etchedy-pro-save-component',
                'args_template' => ['dry_run' => true],
                'why'           => 'Validate. Show the user every warning — especially properties without defaults — before writing.',
            ],
            [
                'ability'       => 'nibwp/etchedy-pro-save-component',
                'args_template' => ['dry_run' => false],
                'why'           => 'Write, once validation is clean and the user has seen the warnings.',
            ],
        ],
        'forbidden_actions' => [
            'Save a component carrying a post id, term id or wp_block ref from this site',
            'Save a component with an absolute wp-content URL in it',
            'Save a component with no properties when any of its text, images or links would differ between uses',
            'Write directly to the Etchedy data folder on disk',
            'Call Etchedy_REST_API static methods instead of the etchedy-manage ability',
            'Modify a stored component as a side effect of reusing it',
        ],
        'before_answering' => 'List the library before proposing a folder. Dry-run before saving. A component going into a library is going to a second site — if anything in it is true only of this one, fix that first.',
    ],

    'preflight_questions' => [
        [
            'key'       => 'brand_prefix',
            'prompt'    => 'BEM brand prefix? (every class will be {prefix}-...)',
            'detect'    => 'shared(brand_prefix)|get_option(nibwp_etchwp_brand)|scan(etch_styles)',
            'type'      => 'string',
            'required'  => true,
            'cache_key' => 'brand_prefix',
        ],
    ],
];
