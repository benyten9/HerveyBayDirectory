<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: the Unlimited Elements widget pack for Elementor.
 *
 * This descriptor declares neither templates nor modules, and both omissions are
 * findings rather than gaps.
 *
 * The pack keeps its widget catalogue in tables of its own — one row per widget
 * in {prefix}addonlibrary_addons, joined to {prefix}addonlibrary_categories —
 * and a widget is switched off by setting that row's is_active column to 0 with
 * a hand-written UPDATE. There is no option and no post holding that state, so
 * there is nothing a read/write closure pair could honestly stand in front of:
 * the engine's module actions are for state a closure can read and write
 * cheaply, and a write here would mean this integration issuing SQL against
 * another plugin's schema. Switching a widget is left to the add-on's own
 * Elements screen, and list_modules is not offered rather than offered broken.
 *
 * The templates are the same story from the other side. The pack's code does
 * define a post type, unelements_library, but the method that registers it has
 * no callers anywhere in the plugin, so on a live site it is never registered.
 * What the owner installs from the add-on's library is saved into Elementor's
 * own elementor_library, stamped with the pack's source meta — Elementor's
 * posts, reachable through the Elementor abilities, and not this pack's to
 * claim. Declaring a post type here would add five actions that refuse on every
 * site.
 *
 * What is left is worth having on its own: the widget inventory, one widget's
 * control schema, and which pages are built with it. Everything else — the
 * actions, the capability checks, the refusals — comes from the shared engine.
 */

// Guarded at the require site: a Skill add-on may ship its own copy of the
// engine at a different path, and require_once cannot see a duplicate it was
// never given. Two copies means two declarations and a fatal on compile.
if (!function_exists('nibwp_elpack_register')) {
    require_once __DIR__ . '/elementor-pack-engine.php';
}

nibwp_elpack_register([
    'key' => 'unlimited-elements',
    'name' => 'Unlimited Elements for Elementor',
    'dir' => 'unlimited-elements-for-elementor',
    // The constant is set by the main plugin file itself, before anything else
    // is loaded, so it holds even on the startup paths where the plugin bails
    // out early and none of its classes exist.
    'constants' => ['UNLIMITED_ELEMENTS_INC', 'UNLIMITED_ELEMENTS_VERSION'],
    'classes' => ['GlobalsUnlimitedElements', 'UniteCreatorElementorIntegrate'],
    'functions' => ['uefe_fs'],
    'description' => 'Take stock of this widget pack on this site: list the widgets it contributes to the editor, read one widget\'s full control schema so a layout can be built '
        . 'with it, and find which pages and templates already use it. This is inventory and usage rather than management — the pack keeps its widget catalogue and each '
        . 'widget\'s on/off state in database tables of its own, so widgets are switched on and off from the add-on\'s own Elements screen, and its templates are saved as '
        . 'Elementor library posts that the Elementor abilities handle.',
    'notes' => [
        "THE CATALOGUE IS A DATABASE TABLE, not an option and not a post type.\n"
        . "{prefix}addonlibrary_addons holds one row per widget, joined to\n"
        . "{prefix}addonlibrary_categories, and the row's is_active column is the\n"
        . "on/off state. The query that hands widgets to Elementor takes is_active=1\n"
        . "only, so list_widgets, describe_widget, find_usage and the widget count in\n"
        . "info all see the switched-ON widgets and nothing else. A widget missing\n"
        . "from list_widgets is not proof the pack lacks it — it may simply be\n"
        . "switched off. There is no list_modules here and nothing in this ability\n"
        . "switches one: that is the add-on's own Elements screen.",

        "WIDGET NAMES are ucaddon_<alias>, built from the addon row's alias, and the\n"
        . "title is that row's title. Pass the ucaddon_ name to describe_widget and\n"
        . "find_usage, not the title. A whole category the owner has consolidated\n"
        . "into a single widget appears instead as ucaddon_cat_<category alias>.\n"
        . "The category each widget reports is not a readable label: it is\n"
        . "uc_category_<numeric id> pointing at a row in the pack's own categories\n"
        . "table, or 'unlimited_elements' for a widget filed under nothing. So group\n"
        . "these widgets by name, and do not show an owner a category id as if it\n"
        . "were the name they gave that group.",

        "NO TEMPLATE POST TYPE, so no template actions. The pack's code defines\n"
        . "unelements_library but never calls the method that would register it, and\n"
        . "the templates an owner installs from the add-on's library are saved as\n"
        . "Elementor's own elementor_library posts, marked with the meta\n"
        . "_unlimited_template_source = 'unlimited' and _unlimited_template_sourceid.\n"
        . "Work with them through the Elementor abilities. Filtering elementor_library\n"
        . "on that source meta is how you tell which of them came from this pack.",

        "A SHORT WIDGET LIST CAN BE A MEMORY CONDITION. Each widget class is created\n"
        . "for the request by eval'ing a subclass of the pack's one generic widget\n"
        . "class while Elementor builds its registry, and that loop skips registering\n"
        . "a widget when PHP is near its memory limit rather than failing. So a list\n"
        . "shorter than the owner expects means either widgets switched off or the\n"
        . "registry cut short, and neither shows up as an error. Check the row count\n"
        . "in the addons table before telling an owner a widget is gone.",

        "THE PAID BUILD carries its own plugin slug, so it can sit in a different\n"
        . "plugin directory than the one this integration resolves widgets against.\n"
        . "On such an install the pack still reports as active — the constants are the\n"
        . "same — while the widget count comes back zero. Zero widgets alongside an\n"
        . "active pack is that case, not an empty catalogue.",
    ],
]);
