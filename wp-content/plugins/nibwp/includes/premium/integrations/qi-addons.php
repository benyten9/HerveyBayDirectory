<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: the Qi widget pack for Elementor.
 *
 * No templates. There is not one register_post_type call in the pack, so the
 * descriptor declares none and the engine offers no template actions. What the
 * pack owns is one on/off list, behind its Widgets screen: 62 widget folders in
 * 1.11.1, each switchable on its own.
 *
 * That list is stored inverted and keyed two ways at once. The option holds ONLY
 * the widgets that are switched off, as shortcode base => widget folder name —
 * and the pack's own gate, qi_addons_for_elementor_is_widget_disabled(), tests
 * the folder name against the option's VALUES, never its keys. The keys are for
 * the settings screen alone. So the read below inverts the option against the
 * pack's own shortcode catalogue, matching on the value, and the write puts back
 * both halves of the pair in the pack's format.
 *
 * The catalogue is the pack's shortcode registry rather than a glob of disk,
 * because that registry is loaded for every widget folder whether it is switched
 * on or not — so a widget that is off is still listed here and can be switched
 * back on. It is an instance property populated once on elementor/init, not a
 * static cache of the option, so reading it after a write cannot go stale.
 *
 * One provenance note, because this pack is the reason the widget-inventory code
 * warns about it: its widget classes live under three roots, one of which is its
 * own inc/plugins/ directory, so a widget's file path contains "/plugins/" twice.
 * The engine resolves provenance by the pack's plugin directory appearing in the
 * path, which is true of all three roots and ambiguous in none of them.
 *
 * Everything else — the actions, the capability checks, the refusals — comes
 * from the shared engine.
 */

// Guarded at the require site: a Skill add-on may ship its own copy of the
// engine at a different path, and require_once cannot see a duplicate it was
// never given. Two copies means two declarations and a fatal on compile.
if (!function_exists('nibwp_elpack_register')) {
    require_once __DIR__ . '/elementor-pack-engine.php';
}

/**
 * Where the pack keeps its switched-off list.
 *
 * Read from the pack's own constant, because the pack itself never names the
 * option any other way — a version that renames it renames it there too. The
 * literal is only the answer for a site where the pack is not loaded at all,
 * which is every call that is about to refuse anyway.
 */
function nibwp_qi_addons_option_name(): string
{
    return defined('QI_ADDONS_FOR_ELEMENTOR_DISABLED_WIDGETS')
        ? (string) constant('QI_ADDONS_FOR_ELEMENTOR_DISABLED_WIDGETS')
        : 'qi_addons_for_elementor_disabled_widgets';
}

/**
 * The switched-off list exactly as stored: shortcode base => widget folder.
 *
 * Absent until the owner saves the pack's Widgets screen once, and absent means
 * every widget is on — not that none of them are.
 *
 * The keys come back as PHP array keys, so a numeric one arrives as an int
 * however it was stored. Every read of one below casts rather than assumes.
 *
 * @return array<array-key, string>
 */
function nibwp_qi_addons_disabled_pairs(): array
{
    $saved = get_option(nibwp_qi_addons_option_name(), []);

    if (!is_array($saved)) {
        return [];
    }

    $pairs = [];

    foreach ($saved as $base => $folder) {
        if (is_scalar($folder) && (string) $folder !== '') {
            $pairs[(string) $base] = (string) $folder;
        }
    }

    return $pairs;
}

/**
 * Every widget the pack can switch, as shortcode base => widget folder name.
 *
 * Both halves are needed: the base is what an agent works with, because it is
 * also the Elementor widget name, and the folder is what the pack's gate reads.
 *
 * @return array<string, string>|WP_Error
 */
function nibwp_qi_addons_catalogue(): array|WP_Error
{
    // The pack publishes its framework object in this global on plugins_loaded.
    // Reading the global rather than the singleton's accessor keeps this from
    // constructing a framework on a site where the pack never started one.
    $root = $GLOBALS['qi_addons_for_elementor_framework'] ?? null;

    if (!is_object($root) || !method_exists($root, 'get_shortcodes')) {
        return new WP_Error(
            'nibwp_qi_addons_no_catalogue',
            __('This add-on has not published its widget catalogue on this request.', 'nibwp'),
            ['status' => 400]
        );
    }

    // Two methods of the same name, one step apart: the framework's returns the
    // shortcode manager, and the manager's returns the shortcodes themselves.
    $manager = $root->get_shortcodes();

    if (!is_object($manager) || !method_exists($manager, 'get_shortcodes')) {
        return new WP_Error(
            'nibwp_qi_addons_no_catalogue',
            __('This add-on has not published its widget catalogue on this request.', 'nibwp'),
            ['status' => 400]
        );
    }

    $catalogue = [];

    foreach ((array) $manager->get_shortcodes() as $base => $shortcode) {
        if (!is_object($shortcode) || !method_exists($shortcode, 'get_shortcode_path')) {
            continue;
        }

        // The path is a URL, and its last segment is the folder the pack's gate
        // compares against. Backslashes cannot appear in a URL, but a filter may
        // have handed the pack a filesystem path, so both separators are handled.
        $folder = basename(str_replace('\\', '/', (string) $shortcode->get_shortcode_path()));

        if ((string) $base !== '' && $folder !== '') {
            $catalogue[(string) $base] = $folder;
        }
    }

    if ($catalogue === []) {
        return new WP_Error(
            'nibwp_qi_addons_no_catalogue',
            __('This add-on lists no widgets to switch on this request. It builds its catalogue when Elementor initialises.', 'nibwp'),
            ['status' => 400]
        );
    }

    return $catalogue;
}

/**
 * Every widget slug the pack offers and whether it is switched on.
 *
 * @return array<string, bool>|WP_Error
 */
function nibwp_qi_addons_widget_state(): array|WP_Error
{
    $catalogue = nibwp_qi_addons_catalogue();

    if ($catalogue instanceof WP_Error) {
        return $catalogue;
    }

    $off = nibwp_qi_addons_disabled_pairs();

    // INVERTED, AND MATCHED ON THE VALUE: the option lists what is OFF, and the
    // pack decides by folder name. Testing the key instead would report a widget
    // as on whenever the stored key and the current base disagree.
    $off_folders = array_values($off);
    $state = [];

    foreach ($catalogue as $base => $folder) {
        $state[$base] = !in_array($folder, $off_folders, true);
    }

    // A stored entry the catalogue no longer has — a widget this version dropped,
    // or one whose set needs another plugin that is no longer installed — is
    // still switched off. Reporting it is what makes switching it back possible.
    foreach (array_keys($off) as $stored) {
        if (!array_key_exists((string) $stored, $state)) {
            $state[(string) $stored] = false;
        }
    }

    return $state;
}

/**
 * Switch one widget on or off, in the pack's own storage format.
 */
function nibwp_qi_addons_set_widget(string $slug, bool $on): bool|WP_Error
{
    $state = nibwp_qi_addons_widget_state();

    if ($state instanceof WP_Error) {
        return $state;
    }

    if (!array_key_exists($slug, $state)) {
        return new WP_Error(
            'nibwp_qi_addons_unknown_widget',
            __('That widget is not one this add-on offers.', 'nibwp'),
            ['status' => 400]
        );
    }

    $off = nibwp_qi_addons_disabled_pairs();
    $catalogue = nibwp_qi_addons_catalogue();

    $folder = $catalogue instanceof WP_Error ? '' : (string) ($catalogue[$slug] ?? '');

    // A slug the catalogue has lost is still known from its stored pair, which is
    // the only way to switch one back on.
    if ($folder === '') {
        $folder = (string) ($off[$slug] ?? '');
    }

    if ($folder === '') {
        return new WP_Error(
            'nibwp_qi_addons_no_folder',
            __('This add-on does not say which of its widget folders that slug belongs to, so it cannot be switched from here.', 'nibwp'),
            ['status' => 500]
        );
    }

    if ($on) {
        // Both halves have to go. The pack's gate reads the values, so an entry
        // left behind under a different key would keep the widget switched off.
        $off = array_filter(
            $off,
            static fn($value, $key): bool => (string) $key !== $slug && (string) $value !== $folder,
            ARRAY_FILTER_USE_BOTH
        );
    } else {
        $off[$slug] = $folder;
    }

    update_option(nibwp_qi_addons_option_name(), $off);

    return true;
}

nibwp_elpack_register([
    'key' => 'qi-addons',
    'name' => 'Qi Addons for Elementor',
    'dir' => 'qi-addons-for-elementor',
    'constants' => ['QI_ADDONS_FOR_ELEMENTOR_VERSION', 'QI_ADDONS_FOR_ELEMENTOR_ABS_PATH'],
    'classes' => ['QiAddonsForElementor'],
    'functions' => ['qi_addons_for_elementor_is_widget_disabled'],
    'description' => 'Manage this widget pack on this site: report its version, list every widget it contributes to the Elementor editor, read one widget\'s full '
        . 'control schema, find the pages built with it, and switch any single widget off or back on. It keeps no templates of its own, so this is inventory, '
        . 'usage and the widget on/off list rather than a template library.',
    'modules' => [
        'label' => 'widget',
        'read' => 'nibwp_qi_addons_widget_state',
        'write' => 'nibwp_qi_addons_set_widget',
    ],
    'notes' => [
        "NO TEMPLATES OF ITS OWN: this pack registers no post type and keeps no\n"
        . "template library, so there is nothing here to list, create or delete. Some\n"
        . "of its widgets do take an existing Elementor section, container or page\n"
        . "template by id and render it — those are ordinary Elementor library\n"
        . 'templates, and the Elementor abilities cover them.',
        "SLUGS ARE THE WIDGET NAMES: unusually, list_modules and list_widgets agree.\n"
        . "A widget's Elementor name is the same string as its slug here, so\n"
        . "'qi_addons_for_elementor_accordion' in one is the same widget in the\n"
        . 'other and the two lists can be paired straight off.',
        "A WIDGET SWITCHED OFF IS NOT REGISTERED AT ALL: the pack skips including\n"
        . "its class, so it is missing from list_widgets and from the editor, while\n"
        . "every page already using it keeps it in its saved data and renders that\n"
        . "section as nothing. Run find_usage before switching one off, and\n"
        . 're-enable rather than rebuild.',
        "STYLING LAGS A CHANGE MADE HERE: the pack concatenates the CSS of the\n"
        . "widgets that are on into one stylesheet, and only its own Widgets screen\n"
        . "regenerates that file. It ships complete, so on a site whose owner has\n"
        . "never saved that screen a widget switched on here is styled normally.\n"
        . "Once they have saved it, a widget switched on from here renders unstyled\n"
        . 'until they open that screen and save it once more.',
        "THE LIST IS SHORTER ON SOME SITES: the WooCommerce, Contact Form 7 and\n"
        . "WPForms widgets only enter the list when the plugin they are for is\n"
        . "installed. A slug missing from list_modules on one site is not\n"
        . 'necessarily missing from the pack.',
        "STORED INVERTED: the option behind this holds only the widgets that are\n"
        . "OFF, keyed one way and matched by the pack another. list_modules resolves\n"
        . "all of that already — read it rather than the option, and never write the\n"
        . 'option directly.',
    ],
]);
