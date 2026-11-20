<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: the Dynific global template pack for Elementor.
 *
 * Templates are `ae_global_templates` posts — owner-managed content with its own
 * admin menu, its own Add New, and a meta box on every single one of them
 * printing the shortcode that inserts it. `ae_render_mode` says which of ten
 * jobs a template does, and four meta keys hold the display rules for the three
 * modes that have any.
 *
 * The other seven modes take no rule at all, which is why they are declared as
 * taking none: a normal template is placed by its shortcode, a block layout and
 * an ACF block are named in the controls of the widget that renders them, and the
 * 404, search, author and date modes are matched on page type alone — the first
 * template of that mode wins and nothing on the template itself is consulted.
 * Warning that one of those is published with no rule and so "shown nowhere"
 * would be wrong every time, and would teach the reader to skip the audit.
 *
 * The module list is the pack's own widget on/off list. `aep_modules` holds slug
 * => bool with true meaning on, and a slug it does not mention is ON — the
 * default the pack applies itself. The slugs are transcribed below rather than
 * asked of the pack, whose only accessor builds the list into a private property
 * on an Elementor hook and would answer a read after a write from that property.
 *
 * Everything else — the actions, the capability checks, the refusal to touch a
 * post that is not one of these — comes from the shared engine.
 */

// Guarded at the require site: a Skill add-on may ship its own copy of the
// engine at a different path, and require_once cannot see a duplicate it was
// never given. Two copies means two declarations and a fatal on compile.
if (!function_exists('nibwp_elpack_register')) {
    require_once __DIR__ . '/elementor-pack-engine.php';
}

/**
 * The module slugs this pack ships.
 *
 * Transcribed from the pack's own list rather than read from it: that list is
 * built inside its module manager on an Elementor hook and kept in a private
 * property, so there is no accessor that is both available on a REST request and
 * current after a write. The slug is the module key, not the folder it lives in —
 * the post author module is keyed 'author'.
 *
 * @return array<int, string>
 */
function nibwp_anywhere_elementor_default_modules(): array
{
    return [
        'author',
        'post-comments',
        'post-content',
        'post-image',
        'post-meta',
        'post-navigation',
        'post-title',
    ];
}

/**
 * Which of the pack's modules are switched on.
 *
 * @return array<string, bool>|WP_Error
 */
function nibwp_anywhere_elementor_module_state(): array|WP_Error
{
    if (!class_exists('Aepro\Classes\ModuleManager')) {
        return new WP_Error(
            'nibwp_anywhere_elementor_no_modules',
            __('This version of the add-on has no module list this integration can switch.', 'nibwp'),
            ['status' => 400]
        );
    }

    $saved = get_option('aep_modules', []);
    $saved = is_array($saved) ? $saved : [];
    $state = [];

    // ABSENT MEANS ON. The pack fills in true for any slug the option has no
    // value for, and ignores the option entirely until it has been saved once,
    // so an untouched install has every module running. Reading a missing slug
    // as off would report the opposite of what the owner sees in the editor.
    foreach (nibwp_anywhere_elementor_default_modules() as $slug) {
        $state[$slug] = array_key_exists($slug, $saved) ? (bool) $saved[$slug] : true;
    }

    // A slug in the option that this build does not ship arrived through the
    // pack's own modules filter — its premium build adds whole groups that way.
    // Reporting it is what makes it possible to switch back on from here.
    foreach ($saved as $slug => $on) {
        if (!array_key_exists((string) $slug, $state)) {
            $state[(string) $slug] = (bool) $on;
        }
    }

    return $state;
}

/**
 * Switch one module on or off, in the pack's own storage format.
 */
function nibwp_anywhere_elementor_set_module(string $slug, bool $on): bool|WP_Error
{
    $state = nibwp_anywhere_elementor_module_state();

    if ($state instanceof WP_Error) {
        return $state;
    }

    if (!array_key_exists($slug, $state)) {
        return new WP_Error(
            'nibwp_anywhere_elementor_unknown_module',
            __('That module is not one this add-on offers.', 'nibwp'),
            ['status' => 400]
        );
    }

    $saved = get_option('aep_modules', []);

    // The option is absent until the pack's own settings screen has been saved
    // once, and the pack writes the first slug straight onto that false. Start
    // from an array instead, so the first switch from here keeps working and
    // every slug already in the option survives it.
    $saved = is_array($saved) ? $saved : [];
    $saved[$slug] = $on;

    update_option('aep_modules', $saved);

    return true;
}

nibwp_elpack_register([
    'key' => 'anywhere-elementor',
    'name' => 'Dynific Addons for Elementor',
    'dir' => 'anywhere-elementor',
    'constants' => ['AE_PRO_VERSION', 'AE_PLUGIN_VERSION'],
    'classes' => ['Aepro\Plugin', 'Aepro\Classes\ModuleManager'],
    'functions' => ['aepro_is_pro_active'],
    'description' => 'Manage this global template pack on this site: list the templates it keeps in one library, create one, set which post type or taxonomy a template '
        . 'takes over and whether it applies to all of them, and switch the pack\'s own modules on or off. Every template can also be inserted anywhere by shortcode, '
        . 'so the audit only reports the three kinds that really are matched by a stored rule.',
    'templates' => [
        [
            'post_type' => 'ae_global_templates',
            'kind_meta' => 'ae_render_mode',
            'kinds' => [
                'normal',
                'post_template',
                'archive_template',
                'post_type_archive_template',
                'block_layout',
                '404',
                'search',
                'author_template',
                'date_template',
                'acf_repeater_layout',
            ],
            // Output by something other than a rule: the shortcode for a normal
            // template, the listing widget that names a block layout, the ACF
            // widget that renders an ACF block, and the page type alone for the
            // last four — where the first template of that mode wins and nothing
            // on the template is read. None of them can be "shown nowhere".
            'kinds_without_conditions' => [
                'normal',
                'block_layout',
                'acf_repeater_layout',
                '404',
                'search',
                'author_template',
                'date_template',
            ],
            // The pack's own Add New always writes a render mode, and a template
            // without one is classified by nothing: its finder skips it and its
            // Render Mode column is blank. 'normal' is the mode that needs no
            // rule, since the shortcode outputs it. Passing kind overrides this.
            'create_meta' => ['ae_render_mode' => 'normal'],
            'conditions' => [
                'apply_global' => 'ae_apply_global',
                'post_type' => 'ae_rule_post_type',
                'taxonomy' => 'ae_rule_taxonomy',
                'post_type_archive' => 'ae_rule_post_type_archive',
            ],
        ],
    ],
    'modules' => [
        'label' => 'module',
        'read' => 'nibwp_anywhere_elementor_module_state',
        'write' => 'nibwp_anywhere_elementor_set_module',
    ],
    'notes' => [
        "TEMPLATE KINDS: ten render modes, and what outputs a template depends\n"
        . "entirely on which one it is. normal is inserted by the shortcode\n"
        . "[dynific_template id=\"<id>\"] — the older [INSERT_ELEMENTOR id=\"<id>\"]\n"
        . "still works — and every template has that shortcode whatever its mode.\n"
        . "post_template, archive_template and post_type_archive_template take over\n"
        . "a single post, a taxonomy archive and a post type archive by rule.\n"
        . "block_layout and acf_repeater_layout are named in the controls of the\n"
        . "widget that renders them, which is a widget from the pack's premium build,\n"
        . "so in this one nothing reads them at all. 404, search, author_template and\n"
        . "date_template are matched on page type alone, and the first template of the\n"
        . "mode wins, so a second one is simply never reached.",

        "DISPLAY RULES are four meta keys, and which of them matters depends on the\n"
        . "kind. post_template reads apply_global together with post_type; a global\n"
        . "single template takes over every post of that type. archive_template reads\n"
        . "apply_global together with taxonomy. post_type_archive_template reads\n"
        . "post_type_archive on its own and needs no global flag. apply_global holds\n"
        . "the literal string \"true\" — that is what the pack writes and what its\n"
        . "hook check compares against, so pass the string rather than a boolean.\n"
        . "The other kinds ignore all four; setting them there stores a value nothing\n"
        . "reads.",

        "WHICH TEMPLATE WINS IS CACHED in four transients the pack drops whenever a\n"
        . "template is saved and rebuilds on the next read. update_template touches the\n"
        . "post, so that drop happens on the same request and the new rule is live at\n"
        . "once rather than after the cache happens to expire.",

        "SOME PLACEMENTS ARE NOT ON THE TEMPLATE AT ALL, so they are invisible from\n"
        . "here and the audit cannot see them either. A single post can name one\n"
        . "template in its own ae_post_template meta, which beats the global rule; a\n"
        . "term can name one in ae_term_template term meta; an author can name one in\n"
        . "ae_author_template user meta. So a published post_template with no rule of\n"
        . "its own may still be in use one post at a time.",

        "THIS BUILD RESOLVES TWO MODES ONLY. Without the pack's premium extension it\n"
        . "matches a template on singular pages and nowhere else, and only for the\n"
        . "post and page post types; the archive, post type archive, block layout,\n"
        . "404, search, author, date and ACF modes are stored and never matched, and\n"
        . "the hook placement that a normal template's usage area describes never\n"
        . "runs. Creating a template in one of those modes is not an error — it is a\n"
        . "layout waiting for the extension — but nothing on the site will change.",

        "MODULES: the option holds slug => true for on and false for off, and a slug\n"
        . "it does not mention is ON. A module is a group of widgets, so switching one\n"
        . "off takes its widgets out of the editor while any page already built with\n"
        . "one keeps it in its saved data and renders that section as nothing. Run\n"
        . "find_usage before switching anything off.",

        "PAGE LAYOUT is the pack's own ae_elementor_template meta rather than the\n"
        . "theme's page template — empty for the theme's layout, 'ec' for Elementor\n"
        . "Canvas, 'ehf' for Elementor Full Width. The preview meta beside it only\n"
        . "decides which post or term the editor shows while the template is being\n"
        . "built, and changes nothing on the front end.",
    ],
]);
