<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: the Essential Addons element pack for Elementor.
 *
 * Templates are `ea_theme_builder` posts. `_ea_template_type` says whether one is
 * a header or a footer, and a single meta key — `_ea_template_conditions` — holds
 * every display rule as a list of rows rather than one key per rule.
 *
 * The element on/off list is the `eael_save_settings` option: a map of slug =>
 * truthy whose keys come from the pack's own `config.php` registries. The pack
 * reads it with its own all-on defaults merged UNDERNEATH, so a slug the option
 * has never mentioned is on and not off — reporting it as off would have an agent
 * switch on something that was never off.
 *
 * The read below rebuilds that state from the option and the config registries
 * rather than calling the pack's `get_settings()`, which needs a Bootstrap
 * instance and answers with the enabled slugs only, losing which slugs exist.
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
 * Every element and extension slug this pack knows about.
 *
 * The pack builds its defaults from `config.php` put through two filters, which
 * is how its Pro companion contributes extra elements to the same option — so the
 * filters are applied here too, or Pro's elements would read as unknown. The
 * widgets and the page extensions are two registries but one option, and the
 * pack's own settings screen writes them together, so both are read.
 *
 * @return array<int, string>
 */
function nibwp_eael_element_slugs(): array
{
    $config = $GLOBALS['eael_config'] ?? null;

    if (!is_array($config)) {
        return [];
    }

    $groups = [
        'elements' => 'eael/registered_elements',
        'extensions' => 'eael/registered_extensions',
    ];

    $slugs = [];

    foreach ($groups as $group => $filter) {
        $registry = $config[$group] ?? null;

        if (!is_array($registry)) {
            continue;
        }

        $registry = apply_filters($filter, $registry);

        if (!is_array($registry)) {
            continue;
        }

        foreach (array_keys($registry) as $slug) {
            $slugs[(string) $slug] = true;
        }
    }

    return array_keys($slugs);
}

/**
 * The element slugs this pack offers and whether each is switched on.
 *
 * @return array<string, bool>|WP_Error
 */
function nibwp_eael_element_state(): array|WP_Error
{
    $slugs = nibwp_eael_element_slugs();

    if ($slugs === []) {
        return new WP_Error(
            'nibwp_eael_no_element_list',
            __('This version of the add-on does not publish a list of elements that can be switched off.', 'nibwp'),
            ['status' => 400]
        );
    }

    $saved = get_option('eael_save_settings', []);
    $saved = is_array($saved) ? $saved : [];
    $state = [];

    foreach ($slugs as $slug) {
        // Absence means on. The stored value is truthy rather than boolean — the
        // settings screen writes true/false, the setup wizard 1 and '', and the
        // activation default 1 — so every one of them is read as a flag.
        $state[$slug] = array_key_exists($slug, $saved) ? (bool) $saved[$slug] : true;
    }

    return $state;
}

/**
 * Switch one element on or off, in the pack's own storage format.
 */
function nibwp_eael_set_element(string $slug, bool $on): bool|WP_Error
{
    $state = nibwp_eael_element_state();

    if ($state instanceof WP_Error) {
        return $state;
    }

    if (!array_key_exists($slug, $state)) {
        return new WP_Error(
            'nibwp_eael_unknown_element',
            __('That element is not one this add-on offers.', 'nibwp'),
            ['status' => 400]
        );
    }

    $state[$slug] = $on;

    // The pack's settings screen writes the whole map — every slug it knows,
    // present as a flag, and anything else dropped — and reads it back the same
    // way. Writing one key on its own would leave the option in a shape the
    // screen never produces.
    update_option('eael_save_settings', $state);

    return true;
}

nibwp_elpack_register([
    'key' => 'eael',
    'name' => 'Essential Addons for Elementor',
    'dir' => 'essential-addons-for-elementor-lite',
    'constants' => ['EAEL_PLUGIN_VERSION'],
    'classes' => ['\Essential_Addons_Elementor\Classes\Bootstrap'],
    'functions' => ['eael_neutralize_shortcodes'],
    'description' => 'Manage the Essential Addons theme builder templates on this site: list the headers and footers it stores, create one, and set the display '
        . 'rules that decide where each appears. Also switches the pack\'s elements and page extensions on or off, and reports templates that are published '
        . 'with no display rules, so they render nowhere.',
    'templates' => [
        [
            'post_type' => 'ea_theme_builder',
            'kind_meta' => '_ea_template_type',
            'kinds' => ['header', 'footer'],
            // What the pack stamps beside the builder flag when it creates a
            // template. Elementor resolves which document class the editor loads
            // from the template type, so without it a template renders but opens
            // as an ordinary post; the canvas page template keeps the theme's
            // header and footer from being wrapped around it.
            'create_meta' => [
                '_elementor_template_type' => 'ea-theme-builder',
                '_wp_page_template' => 'elementor_canvas',
            ],
            // One key, not one per rule: every include and exclude row lives in
            // this list and carries its own type.
            'conditions' => [
                'rules' => '_ea_template_conditions',
            ],
        ],
    ],
    'modules' => [
        'label' => 'element',
        'read' => 'nibwp_eael_element_state',
        'write' => 'nibwp_eael_set_element',
    ],
    'notes' => [
        "TEMPLATE KINDS: header and footer. The kind is not optional — the pack\n"
        . "looks templates up by their `_ea_template_type` value, so one saved\n"
        . "without a kind is never matched however its rules read. A site can\n"
        . "register further kinds through the pack's own hook; only the two it ships\n"
        . "are accepted here, so a custom kind has to be set from its own screen.",

        "DISPLAY RULES: `rules` is one list of rows, each of them\n"
        . "{type: include|exclude, name: general|archive|singular, sub_name: a\n"
        . "narrower rule or '', sub_id: an object id or 0}. A template shows where at\n"
        . "least one include row matches and no exclude row does, and the most\n"
        . "specific include wins. The pack drops any row it cannot evaluate as it\n"
        . "reads the meta, silently: a name outside those three, or a sub_name that\n"
        . "does not sit under it, disappears and the template renders nowhere, and a\n"
        . "set of nothing but exclude rows never matches at all. So read a template\n"
        . "that already works with get_template and copy the shape it comes back in.",

        "CACHING: which template wins for a request is cached for a day, and the\n"
        . "pack drops that cache only when a template is saved, moved between\n"
        . "statuses or deleted. update_template touches the post itself only when a\n"
        . "title or a status comes with the call, so send `status` alongside `rules`\n"
        . "— the value it already has is fine — or the new rules sit unread until the\n"
        . "cache expires.",

        "ELEMENTS: the list covers the widgets and the page extensions together,\n"
        . "because they share one option. A slug that option has never mentioned is\n"
        . "ON: the pack merges its own all-on defaults underneath it. The resolved\n"
        . "state list_modules reports is the one the pack registers widgets from —\n"
        . "but some elements carry a dependency check as well, so one whose form or\n"
        . "shop plugin is not active is skipped however this reports it. list_widgets\n"
        . "is what actually reached the registry.",

        "SWITCHING AN ELEMENT writes the pack's option and nothing else. Its own\n"
        . "settings screen also empties the CSS and JS it generated into the uploads\n"
        . "folder, and those files are reused for as long as they exist — so after\n"
        . "enabling an element, clear that cache from the add-on's settings screen or\n"
        . "pages will keep loading assets built without it. An element that\n"
        . "Elementor's own element manager has switched off also stays off whatever\n"
        . "this reports.",

        "NEW TEMPLATES: Elementor picks the editor's document class from\n"
        . "`_elementor_template_type`, which the pack stamps on a template created\n"
        . "from its dashboard and create_template here cannot write. A template made\n"
        . "from this ability still renders — that only needs the builder flag, which\n"
        . "is written — but it opens in the editor as an ordinary post document. When\n"
        . "the editing experience matters, create it from the add-on's dashboard and\n"
        . "manage it from here afterwards.",
    ],
]);
