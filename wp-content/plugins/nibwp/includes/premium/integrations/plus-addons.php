<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: The Plus Addons for Elementor.
 *
 * No template post type, so no template actions. What this pack calls a
 * template is an `elementor_library` post it creates from its live editor with a
 * slug of `tp-create-template-<uniqid>`, which belongs to Elementor and is
 * managed with Elementor's own abilities. The three post types the pack does
 * register are content — clients, testimonials, team members — off by default
 * and renameable from its own settings, so they are not templates and are not
 * declared here.
 *
 * The widget on/off list is `theplus_options['check_elements']`: a plain list of
 * the slugs that are ON, in underscore form (`tp_accordion`). A slug that is
 * absent is off, because the pack registers a widget only when its slug is in
 * that list. `theplus_widgets_settings` is NOT this list despite the name — it
 * holds the form widget's captcha keys.
 *
 * Both functions below go straight to the option and to the pack's own literal
 * list of slugs. The pack's own reader, `l_theplus_get_option()`, memoises into
 * a function-local static AND into the persistent object cache, so a read after
 * a write in the same request would hand back the pre-write value.
 *
 * Everything else — the actions, the capability checks — comes from the shared
 * engine.
 */

// Guarded at the require site: a Skill add-on may ship its own copy of the
// engine at a different path, and require_once cannot see a duplicate it was
// never given. Two copies means two declarations and a fatal on compile.
if (!function_exists('nibwp_elpack_register')) {
    require_once __DIR__ . '/elementor-pack-engine.php';
}

/**
 * Every widget slug this pack can switch, as the pack itself lists them.
 *
 * Read off the literal property the pack validates its own settings screen
 * against, rather than off its asset-dependency config: that config is keyed by
 * the widgets' hyphenated Elementor names and mixes editor extensions in, so it
 * is the wrong list in the wrong form for this option.
 *
 * @return array<int, string>|WP_Error
 */
function nibwp_plus_addons_widget_slugs(): array|WP_Error
{
    if (!class_exists('Tpae_Hooks') || !method_exists('Tpae_Hooks', 'get_instance')) {
        return new WP_Error(
            'nibwp_plus_addons_no_widget_list',
            __('This version of the add-on does not publish a list of widgets that can be switched off.', 'nibwp'),
            ['status' => 400]
        );
    }

    $hooks = Tpae_Hooks::get_instance();

    if (!is_object($hooks) || !isset($hooks->all_widgets) || !is_array($hooks->all_widgets)) {
        return new WP_Error(
            'nibwp_plus_addons_no_widget_list',
            __('This version of the add-on does not publish a list of widgets that can be switched off.', 'nibwp'),
            ['status' => 400]
        );
    }

    $slugs = [];

    foreach ($hooks->all_widgets as $slug) {
        if (is_string($slug) && $slug !== '') {
            $slugs[] = $slug;
        }
    }

    return array_values(array_unique($slugs));
}

/**
 * The slugs currently stored as switched on.
 *
 * The pack's registration gate is `in_array( $widget_id, $get_option )`, so it
 * is the option's VALUES that decide, whatever its keys are. Reading the values
 * means a hand-edited option with string keys is read the same way the pack
 * reads it rather than the way it happens to be written.
 *
 * @return array<int, string>
 */
function nibwp_plus_addons_enabled_slugs(): array
{
    $options = get_option('theplus_options', []);

    if (!is_array($options) || !isset($options['check_elements']) || !is_array($options['check_elements'])) {
        return [];
    }

    $enabled = [];

    foreach ($options['check_elements'] as $slug) {
        if (is_string($slug) && $slug !== '') {
            $enabled[] = $slug;
        }
    }

    return array_values(array_unique($enabled));
}

/**
 * The widget slugs this pack offers and whether each is switched on.
 *
 * @return array<string, bool>|WP_Error
 */
function nibwp_plus_addons_widget_state(): array|WP_Error
{
    $slugs = nibwp_plus_addons_widget_slugs();

    if ($slugs instanceof WP_Error) {
        return $slugs;
    }

    $enabled = nibwp_plus_addons_enabled_slugs();
    $state = [];

    // PRESENCE IS THE ENABLEMENT: the option lists only the widgets that are on,
    // so a slug absent from it is off — including on a fresh install, where the
    // pack seeds seventeen of them and leaves the rest switched off.
    foreach ($slugs as $slug) {
        $state[$slug] = in_array($slug, $enabled, true);
    }

    // A slug stored as on that this version's list does not have: a widget from
    // the paid build, or one the pack dropped. Reporting it is what makes it
    // possible to switch it back off from here.
    foreach ($enabled as $slug) {
        if (!array_key_exists($slug, $state)) {
            $state[$slug] = true;
        }
    }

    return $state;
}

/**
 * Switch one widget on or off, in the pack's own storage format.
 */
function nibwp_plus_addons_set_widget(string $slug, bool $on): bool|WP_Error
{
    $state = nibwp_plus_addons_widget_state();

    if ($state instanceof WP_Error) {
        return $state;
    }

    if (!array_key_exists($slug, $state)) {
        return new WP_Error(
            'nibwp_plus_addons_unknown_widget',
            __('That widget is not one this add-on offers.', 'nibwp'),
            ['status' => 400]
        );
    }

    $options = get_option('theplus_options', []);

    if (!is_array($options)) {
        $options = [];
    }

    // Only the one key is touched. The pack's own settings screen replaces the
    // whole option with the payload its dashboard posted, and `theplus_options`
    // also holds the extensions list and other settings, so a write shaped like
    // that one would drop whatever the payload left out.
    $options['check_elements'] = $on
        ? array_values(array_unique(array_merge(nibwp_plus_addons_enabled_slugs(), [$slug])))
        : array_values(array_diff(nibwp_plus_addons_enabled_slugs(), [$slug]));

    update_option('theplus_options', $options);

    // The pack's own Widgets screen clears its combined editor bundle straight
    // after the same write, because that bundle was built from the old widget
    // set and the editor would keep loading it. Skipping this leaves a site in
    // combined-asset mode editing against the widget list it had before.
    if (function_exists('l_theplus_library')) {
        $library = l_theplus_library();

        if (is_object($library) && method_exists($library, 'remove_backend_dir_files')) {
            $library->remove_backend_dir_files();
        }
    }

    return true;
}

nibwp_elpack_register([
    'key' => 'plus-addons',
    'name' => 'The Plus Addons for Elementor',
    'dir' => 'the-plus-addons-for-elementor-page-builder',
    'constants' => ['L_THEPLUS_VERSION', 'L_THEPLUS_FILE', 'L_THEPLUS_PATH'],
    'classes' => ['Tpae_Hooks'],
    'functions' => ['l_registered_widgets', 'l_theplus_get_option'],
    'description' => 'Manage this add-on pack on this site: list the widgets it contributes to the Elementor editor, read one widget\'s full control schema before building with it, '
        . 'find which pages are built with a given widget, and switch any of its widgets on or off. A widget switched off is never registered with Elementor at all, so a page '
        . 'already using it keeps the widget in its saved data and renders nothing in its place — the audit reports how many are off before anything is changed. This pack keeps '
        . 'no template post type of its own, so there are no template actions here.',
    'modules' => [
        'label' => 'widget',
        'read' => 'nibwp_plus_addons_widget_state',
        'write' => 'nibwp_plus_addons_set_widget',
    ],
    'notes' => [
        "TWO SPELLINGS OF THE SAME WIDGET. The module list is the pack's settings\n"
        . "slugs, which use UNDERSCORES: tp_accordion, tp_adv_text_block. The widget\n"
        . "names in the Elementor registry use HYPHENS: tp-accordion,\n"
        . "tp-adv-text-block. So list_modules, enable_module and disable_module take\n"
        . "the underscore form, while list_widgets, describe_widget and find_usage\n"
        . "take the hyphen form. Convert when you move between them; neither side\n"
        . "accepts the other's spelling and neither reports an error, it simply\n"
        . "never matches.",

        "PRESENCE IS THE ENABLEMENT. The pack stores a list of the widgets that are\n"
        . "ON, so a slug missing from it is off, and a fresh install has roughly a\n"
        . "sixth of them on — the rest are switched off, not broken. list_modules\n"
        . "reports the resolved slug => enabled state, so read it rather than the\n"
        . "option. A slug reported that is not in this build's list is a widget from\n"
        . "the paid build or one the pack dropped; it can be switched off from here\n"
        . "but not meaningfully switched on.",

        "THE CHANGE LANDS ON THE NEXT REQUEST. The pack reads its widget list\n"
        . "through a helper that caches into a function-local static as well as the\n"
        . "object cache. The write clears the object cache, but nothing can reset\n"
        . "that static, so within the request that switched a widget the pack itself\n"
        . "still sees the old list. list_modules here reads the option directly and\n"
        . "is correct immediately; the editor catches up on the next page load.",

        "ELEMENTOR'S OWN DISABLED-ELEMENTS LIST WINS LATER. When the pack's\n"
        . "dashboard screen loads, it subtracts from its enabled list every slug\n"
        . "that appears in Elementor's `elementor_disabled_elements` option, and it\n"
        . "rewrites the whole `theplus_options` row while doing so. A widget enabled\n"
        . "from here that is still in Elementor's own disabled list is therefore\n"
        . "switched off again the next time an admin opens the add-on's dashboard,\n"
        . "and other keys in that option can be lost with it. Clear the widget from\n"
        . "Elementor's own Manage Elements screen as well, or expect it back off.",

        "NO TEMPLATES HERE, BY DESIGN. The pack registers no template post type.\n"
        . "What its live editor creates is an ordinary `elementor_library` post with\n"
        . "a slug of tp-create-template-<id> and the page template set to Elementor\n"
        . "Canvas, so list, create and delete it with the Elementor abilities, not\n"
        . "this one. Its three own post types — clients, testimonials and team\n"
        . "members — are content the listing widgets read, are disabled by default,\n"
        . "and can be renamed from the pack's settings, which is why none of them is\n"
        . "declared as a template type.",

        "EXTENSIONS ARE A SECOND LIST THIS DOES NOT TOUCH. Alongside the widget\n"
        . "list, the same option holds about thirty editor extensions — global\n"
        . "buttons, custom CSS, display rules, scroll animations — stored the same\n"
        . "way, presence meaning on. They are not widgets and are not in this\n"
        . "module list, so an extension is switched from the add-on's own Extensions\n"
        . "screen. Do not pass an extension slug to enable_module; it is refused.",

        "THE PLUGIN MAY BE RENAMED ON THIS SITE. The paid build has a white-label\n"
        . "setting that replaces the plugin's name, description, author and logo\n"
        . "throughout wp-admin, and also switches off its own notices. Everything\n"
        . "here keys off the plugin directory and its constants instead, so a\n"
        . "rebranded install behaves identically — but the admin menu, the dashboard\n"
        . "and the plugins list may all call it something else, and that label is not\n"
        . "evidence of a different plugin. Report what the owner sees; do not rename\n"
        . "anything to match it.",

        "THE PACK SHIPS ITS OWN ABILITIES. It registers a category and a set of\n"
        . "abilities under the `tpae/` prefix for its widgets and for Elementor\n"
        . "layout work. Those are the pack's, not this integration's: they are not\n"
        . "managed from here, this ability does not wrap them, and switching a\n"
        . "widget off here does not unregister the ability that builds it.",
    ],
]);
