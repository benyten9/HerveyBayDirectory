<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Skill Manifest — Mosaic Pro.
 *
 * HTML / URL / screenshot / brief → a validated Mosaic page, built through the
 * Mosaic integration.
 *
 * What makes Mosaic different from the other builders here: it is a theme
 * builder with its own data layer, and it is forgiving in the worst way. A
 * value its validators reject is dropped without an error, so a wrong enum or a
 * structured property written as a string becomes an element that renders
 * unstyled and nobody is told. This skill checks every element type, child,
 * data key, state, breakpoint and style value against the vocabulary read from
 * the Mosaic source before anything is written, and the integration reads back
 * what Mosaic kept after it is written. Between the two, nothing is lost quietly.
 *
 * The second difference is the design system. Mosaic has universal classes and
 * components, so a design converted here arrives as classes (from the <style>
 * rules) and repeated sections become components, instead of the same
 * declarations pasted onto every element.
 *
 * Persistence belongs to the Mosaic integration
 * (includes/premium/integrations/mosaic.php), which writes through Mosaic's own
 * editor model. This skill validates, recommends and orchestrates. No $wpdb
 * write, wp_insert_post or update_post_meta in these files —
 * tests/skills/mosaic-pro-check.php parses the sources and fails if one appears.
 */

return [
    'id'             => 'mosaic-pro',
    'name'           => 'Mosaic Pro',
    'tagline'        => 'Turn HTML, URLs, screenshots or a brief into real Mosaic pages — every value checked before Mosaic can silently drop it, styles turned into Mosaic classes, repeated sections into components',
    'description'    => 'Paste HTML, share a URL, describe a screenshot or say what you want, and the agent builds it in Mosaic as real elements: sections, layouts, text with bold, italic and links as Mosaic stores them, buttons, images, lists, video embeds, accordions and tabs. Styles are written per breakpoint and per state (base, tablet, mobile, hover), with anything Mosaic keeps in its own structure written as CSS it validates. Class rules in the HTML become Mosaic universal classes, and a section you will reuse becomes a component placed by reference. Mosaic drops any value it does not accept without telling anyone, so every element type, child, data key, state, breakpoint and style value is checked first, and what Mosaic actually kept is read back after. Existing pages can be inspected (outline, missing alt text, heading order, desktop sizes with no phone value, styles that should be a class) and refined element by element, found by name or words, without rebuilding. Ready blueprints for hero, features, pricing, testimonials, FAQ, stats, header and footer cover the screenshot case.',
    'vendor'         => 'NIBWP',
    'version'        => '1.0.0',
    'category'       => 'page-builders',
    'premium'        => true,
    'price'          => 49,
    'required_plans' => ['pro', 'agency', 'lifetime'],
    'requires'       => ['mosaic'],
    'entitlements'   => ['skill:mosaic', 'skill:mosaic-pro', 'integration:mosaic'],
    'integration_files' => ['mosaic'],
    'features'       => [
        'HTML, a URL, a screenshot or a plain brief becomes a real Mosaic page',
        'Every element type, child, data key, state, breakpoint and style value checked before it is written',
        'Reads back what Mosaic kept after every write, so a dropped value is reported, not lost',
        'Styles per breakpoint and per state: base, tablet, mobile, hover, focus',
        'Bold, italic, links and line breaks stored the way Mosaic stores them',
        'Class rules in the HTML become Mosaic universal classes; repeated styles are flagged as class candidates',
        'Save a section as a component and place it anywhere by reference',
        'Accordion, tabs and navbar built from Mosaic\'s own default structure',
        'Responsive blueprints for hero, features, CTA, pricing, testimonials, FAQ, stats, header and footer',
        'Inspect any page: outline, missing alt, heading order, phone sizes, class and component use',
        'Refine one element by name or by its words, without rebuilding the page',
        'Dry run by default; never switches the site theme; writes nothing itself',
    ],
    'ability_files' => [
        'abilities/convert.php',
        'abilities/build.php',
        'abilities/refine.php',
        'abilities/inspect.php',
    ],
    'instructions_file' => 'authoring/SKILL.md',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="8" height="5" rx="1.5"/><rect x="13" y="3" width="8" height="9" rx="1.5"/><rect x="3" y="10" width="8" height="11" rx="1.5"/><rect x="13" y="14" width="8" height="7" rx="1.5"/></svg>',

    // ─── v2 routing contract ──────────────────────────────────────────────
    'use_when' => 'The user wants anything built, rebuilt, converted, restyled or reviewed in Mosaic on this site: a page, a section, a header or footer, a component, a class. Owns it however it is phrased. Mosaic content lives in its own tables, not post_content or blocks — never build a Mosaic page through a generic post ability.',

    'triggers' => [
        '/(?i)\b(?:convert|rebuild|port|turn|make|build)\b[^.\n]{0,40}\bmosaic\b/',
        '/(?i)\bmosaic\b[^.\n]{0,40}\b(?:page|template|master|component|section|element|class|header|footer|hero|layout|theme)\b/',
        '/(?i)\b(?:html|url|page|file|image|screenshot|mockup|design)\b[^.\n]{0,40}\b(?:to|into|as|in)\b[^.\n]{0,20}\bmosaic\b/',
        '/(?i)\b(?:audit|check|review|inspect)\b[^.\n]{0,30}\bmosaic\b/',
        '/(?i)\b(?:create|build|make|design|add|generate|produce|craft|redesign|rebuild|style|restyle|refine)\b[^.\n]{0,80}\bmosaic\b/',
        '/(?i)\bmosaic\b[^.\n]{0,80}\b(?:create|build|make|design|add|generate|produce|craft|redesign|rebuild|style|restyle|refine)\b/',
    ],
    'commands' => [
        '/mosaic' => [
            'description' => 'Build HTML / a URL / a screenshot / a brief as a Mosaic page or section.',
        ],
        '/mosaic-convert' => [
            'description' => 'Produce the Mosaic tree and classes without writing anything.',
        ],
        '/mosaic-inspect' => [
            'description' => 'Audit a Mosaic page: outline, problems, classes and components used.',
        ],
        '/mosaic-refine' => [
            'description' => 'Change elements of a Mosaic page in place.',
        ],
    ],
    'mandatory_routing' => [
        'preflight_required' => false,
        'pipeline' => [
            [
                'ability'       => 'nibwp/mosaic-status',
                'args_template' => [],
                'why'           => 'Is Mosaic running, set up, and which theme is live. Everything Mosaic shows lives in a theme.',
            ],
            [
                'ability'       => 'nibwp/design-direction',
                'args_template' => ['purpose' => '{what the user asked for, in their words}'],
                'why'           => 'Decide how this site should look before building anything. Skip only if the Design Skills skill is switched off.',
            ],
            [
                'ability'       => 'nibwp/mosaic-list-design',
                'args_template' => [],
                'why'           => 'The theme\'s existing classes and variables. Reuse them before inventing new ones.',
            ],
            [
                'ability'       => 'nibwp/mosaic-pro-convert',
                'args_template' => ['html' => '{the markup}', 'section_type' => '{or a blueprint for a brief}'],
                'why'           => 'Tree, classes and a validation report with nothing written. Fix every error here.',
            ],
            [
                'ability'       => 'nibwp/mosaic-pro-build',
                'args_template' => ['dry_run' => true],
                'why'           => 'Where it would land and what Mosaic would drop, before writing.',
            ],
            [
                'ability'       => 'nibwp/mosaic-pro-build',
                'args_template' => ['dry_run' => false],
                'why'           => 'Write it through the integration once the dry run is clean.',
            ],
        ],
        'forbidden_actions' => [
            'Writing Mosaic content through nibwp/wp-create-post, post_content, post meta or SQL — Mosaic keeps pages in its own tables and editor model',
            'Element types that are not Mosaic elements — check nibwp/mosaic-list-elements',
            'Words written as data on a text element — a text holds wysiwyg-text children (use text: "…" shorthand)',
            'Block elements inside a text — a text holds only inline runs',
            'Structured properties (border, border-radius, box-shadow, transform, transition, grid-template-columns, background images) as style strings — write them as CSS in css',
            'Authoring template-internal, master-root, document, body or template-external — Mosaic owns them',
            'Switching the WordPress theme to make Mosaic pages visible — that is the owner\'s decision in wp-admin → Mosaic',
            'Pasting the same style onto many elements — make a class',
            'Rebuilding a page to change one element — use nibwp/mosaic-pro-refine',
        ],
        'before_answering' => 'Mosaic drops what it does not accept and says nothing, so convert and validate before you build, and read `dropped` after. Build with classes, not repeated inline styles. Say plainly when the Mosaic theme is not live: the work is saved but visitors will not see it until the owner makes the theme live.',
    ],
    'preflight_questions' => [
        [
            'key'       => 'target',
            'prompt'    => 'Where should this go — a new page, an existing page, or a reusable component?',
            'choices'   => ['new_page', 'existing_page', 'component'],
            'type'      => 'enum',
            'required'  => true,
            'cache_key' => 'mosaic_target',
            'cache'     => false,
        ],
        [
            'key'            => 'post_id',
            'prompt'         => 'Which page?',
            'type'           => 'integer',
            'conditional_on' => ['target' => 'existing_page'],
            'cache_key'      => 'mosaic_post_id',
            'cache'          => false,
        ],
    ],
];
