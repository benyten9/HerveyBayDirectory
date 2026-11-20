<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Skill Manifest — Builderius Pro.
 *
 * HTML / URL / screenshot / brief → a validated Builderius config, committed
 * through the Builderius integration.
 *
 * What makes this one different from the other builder skills here: Builderius
 * versions content the way git does. A template owns branches, a branch owns
 * commits, and what visitors see is a *release* — a frozen copy of a commit, not
 * the commit itself. Content is committed, never saved. Every other builder
 * skill writes into one place and is done; here "where does this land" is a real
 * question with a wrong answer, and the wrong answer replaces something somebody
 * published. So this skill names the version it is about to write before it
 * writes it, and refuses to roll over a published release.
 *
 * The second difference is that the setting vocabulary is read from the install.
 * Builderius declares every module setting — its name and the modules it applies
 * to — in its own container config, so a mistyped setting is an error with the
 * module named, before anything is committed, rather than a module that renders
 * with that setting silently dropped.
 *
 * Persistence belongs to the Builderius integration
 * (includes/premium/integrations/builderius.php). This skill validates,
 * recommends and orchestrates; it never touches the database itself. No $wpdb
 * write, no wp_insert_post, no update_post_meta anywhere in these files —
 * tests/skills/builderius-pro-check.php parses the sources and fails if one
 * appears.
 */

return [
    'id'             => 'builderius-pro',
    'name'           => 'Builderius Pro',
    'tagline'        => 'Convert HTML, URLs, screenshots or a brief into validated Builderius configs — modules and settings checked against your own install, committed onto a version that will not clobber your published release',
    'description'    => 'Paste HTML, share a URL, describe a screenshot or just say what you want, and the agent builds it as a real Builderius config: a flat module map linked by parent and index, settings as {name, value}, dynamic data as [[[wp.*]]] tokens, per-module CSS on the %local% selector. Every module name is checked against the module library of this install and every setting name against the modules that actually accept it, so a typo is a message naming the module rather than a section that renders empty. Because Builderius versions content like git, the skill says where the work will land before it lands: a new version on the branch, or a new branch of its own. It reads which release is published and which commit that release froze, and refuses to overwrite a published release — naming the release it would have replaced and the commit serving it. Existing templates and components can be read back: their tree, their unknown or empty modules, their dynamic-data tokens, and whether they are carrying work nobody has released yet. One module can be changed by name without rebuilding the rest, committed as a new version so the old one is still there.',
    'vendor'         => 'NIBWP',
    'version'        => '1.0.0',
    'category'       => 'page-builders',
    'premium'        => true,
    'price'          => 29,
    'required_plans' => ['pro', 'agency', 'lifetime'],
    'requires'       => ['builderius'],
    'entitlements'   => ['skill:builderius', 'skill:builderius-pro', 'integration:builderius'],
    'integration_files' => ['builderius'],
    'features'       => [
        'HTML, a URL, a screenshot or a plain brief becomes a valid Builderius config',
        'Module names validated against the module library of YOUR install, not a written list',
        'Setting names validated against the modules that accept them, read from Builderius own setting registry',
        'A mistyped setting names the module it was put on, and the modules that do accept it',
        'Refuses to commit over a published release — and names the release and the commit it serves',
        'Says which version the work lands on: a new version on the branch, or a new branch',
        'Convert without committing, so the config can be read before anything is written',
        'Inspect any template or component: tree, empty modules, dynamic-data tokens, release state',
        'Tells you when a template is carrying commits nobody has released yet',
        'Change one module by name, committed as a new version — the old one is still there',
        'Section checklists for hero, navbar, footer, CTA, pricing, card grid, testimonial and form',
        'Writes nothing itself — every commit goes through the Builderius integration',
    ],
    'ability_files' => [
        'abilities/convert.php',
        'abilities/inspect.php',
        'abilities/build.php',
        'abilities/refine.php',
    ],
    'instructions_file' => 'authoring/SKILL.md',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="2.5"/><circle cx="6" cy="18" r="2.5"/><circle cx="18" cy="12" r="2.5"/><path d="M6 8.5v7"/><path d="M8.5 6h4a3 3 0 0 1 3 3v1"/><path d="M8.5 18h4a3 3 0 0 0 3-3v-1"/></svg>',

    // ─── v2 routing contract ──────────────────────────────────────────────
    // The regexes are a fast path. This sentence is the boundary.
    'use_when' => 'The user wants anything built, rebuilt, converted or restyled in Builderius on this site, or wants to know what a Builderius template contains and whether it is safe to change. Owns it however it is phrased. Builderius content is a committed config, not post_content and not a block tree — never write a Builderius template through a generic post ability.',

    'triggers' => [
        '/(?i)\b(?:convert|rebuild|port|turn|make|build)\b[^.\n]{0,40}\bbuilderius\b/',
        '/(?i)\bbuilderius\b[^.\n]{0,40}\b(?:template|component|fragment|module|config|branch|commit|version|release|section|page)\b/',
        '/(?i)\b(?:html|url|page|file|image|screenshot|mockup|design)\b[^.\n]{0,40}\b(?:to|into|as)\b[^.\n]{0,20}\bbuilderius\b/',
        '/(?i)\b(?:audit|check|review|inspect)\b[^.\n]{0,30}\bbuilderius\b/',
        // Order-agnostic build intent. Every pattern above needs the builder's
        // name before the thing being built, so the most natural phrasing of a
        // request — the verb first — matched nothing and the skill never loaded.
        '/(?i)\b(?:create|build|make|design|add|generate|produce|craft|redesign|rebuild|style|restyle|refine)\b[^.\n]{0,80}\bbuilderius\b/',
        '/(?i)\bbuilderius\b[^.\n]{0,80}\b(?:create|build|make|design|add|generate|produce|craft|redesign|rebuild|style|restyle|refine)\b/',
    ],
    'commands' => [
        '/builderius' => [
            'description' => 'Convert HTML / a URL / a screenshot / a brief into a validated Builderius config and commit it.',
        ],
        '/builderius-convert' => [
            'description' => 'Produce the config without committing it, so it can be read first.',
        ],
        '/builderius-inspect' => [
            'description' => 'Read a template or component: tree, empty modules, dynamic data, and its release state.',
        ],
        '/builderius-refine' => [
            'description' => 'Change named modules on an existing template, committed as a new version.',
        ],
    ],
    'mandatory_routing' => [
        'preflight_required' => false,
        'pipeline' => [
            [
                'ability'       => 'nibwp/design-direction',
                'args_template' => ['purpose' => '{what the user asked for, in their words}'],
                'why'           => 'Decide how this site should look before building anything. Skip only if the Design Skills skill is switched off.',
            ],
            [
                'ability'       => 'nibwp/builderius-list-modules',
                'args_template' => [],
                'why'           => 'The module vocabulary of THIS install. Add-ons extend it; never assume a module exists.',
            ],
            [
                'ability'       => 'nibwp/builderius-pro-modules',
                'args_template' => ['action' => 'list'],
                'why'           => 'Which settings each module actually accepts, read from Builderius own setting registry on this site. This is the step that stops a mistyped setting being committed.',
            ],
            [
                'ability'       => 'nibwp/builderius-pro-inspect',
                'args_template' => ['action' => 'releases'],
                'why'           => 'Which release is published, and which commit it serves. Read this BEFORE building onto an existing template — it decides whether the work may land on that branch at all.',
            ],
            [
                'ability'       => 'nibwp/builderius-pro-convert',
                'args_template' => ['html' => '{the markup, or the brief}'],
                'why'           => 'Config plus validation report, with nothing written. Fix every error here.',
            ],
            [
                'ability'       => 'nibwp/builderius-pro-build',
                'args_template' => ['dry_run' => true],
                'why'           => 'Confirm where it would land — which template, which version mode, which release is at risk — before writing.',
            ],
            [
                'ability'       => 'nibwp/builderius-pro-build',
                'args_template' => ['dry_run' => false],
                'why'           => 'Commit it through the integration, once validation is clean and the user has seen the version plan.',
            ],
        ],
        'forbidden_actions' => [
            'Writing a Builderius template through nibwp/wp-create-post, post_content or raw meta — Builderius content is a config committed onto a branch',
            'Module names not in this install library — check with nibwp/builderius-list-modules first',
            'Setting names carried over from another builder vocabulary — Builderius declares settings per module, read them with nibwp/builderius-pro-modules',
            'Committing onto a branch whose commit is frozen into the published release without saying so — ask, or build on a new branch',
            'version_mode overwrite on a template the published release serves — it is refused, and it should be',
            'A nested children tree written straight into storage — Builderius stores a FLAT module map linked by parent and index',
            'Settings written as an object map in a stored config — a stored setting is always {"name":…,"value":…}',
            'CSS written with a real selector instead of %local%',
            'Replacing a whole config to change one module — use nibwp/builderius-pro-refine',
            'Hand-repeating a card five times when the content comes from posts — Collection is the loop module',
        ],
        'before_answering' => 'Builderius commits; it does not save. Decide where the work lands before you build it: a new version on the existing branch, or a new branch. Read which release is published first — a release is a frozen copy of a commit, and rolling one forward is how published work disappears. Do not improvise module or setting names; both are published by the install and both are one call away.',
    ],
    'preflight_questions' => [
        [
            'key'       => 'target_kind',
            'prompt'    => 'What is being built — a template, a reusable component, or a saved fragment?',
            'choices'   => ['template', 'component', 'fragment'],
            'type'      => 'enum',
            'required'  => true,
            'cache_key' => 'builderius_target_kind',
        ],
        [
            'key'       => 'version_mode',
            'prompt'    => 'Builderius versions content. Commit a new version on the existing branch, or start a new branch for this work?',
            'choices'   => ['new_version', 'new_branch'],
            'type'      => 'enum',
            'required'  => true,
            'cache_key' => 'builderius_version_mode',
            // Per build, not per site. A remembered answer hands the next task a
            // settled decision about somebody else's template, which is the
            // mistake the release guard exists to catch.
            'cache'     => false,
        ],
        [
            'key'            => 'template_id',
            'prompt'         => 'Which existing template should this be committed onto?',
            'type'           => 'integer',
            'conditional_on' => ['target_kind' => 'template'],
            'cache_key'      => 'builderius_template_id',
            'cache'          => false,
        ],
        [
            'key'       => 'template_type',
            'prompt'    => 'What kind of template is it — the Builderius type taxonomy slug?',
            'choices'   => ['single', 'archive', 'page', 'hook', '404', 'search'],
            'type'      => 'enum',
            'cache_key' => 'builderius_template_type',
        ],
    ],
];
