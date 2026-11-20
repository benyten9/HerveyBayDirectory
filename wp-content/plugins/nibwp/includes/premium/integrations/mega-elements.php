<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: the Mega Elements widget pack for Elementor.
 *
 * No templates. The pack holds no register_post_type call — not a literal one
 * and not one built from a variable — and nothing in it reads or writes an
 * elementor_library post either, so the descriptor claims no post type and the
 * engine offers no template actions rather than five that would refuse on every
 * site.
 *
 * It does own one on/off list, and that list is the inverse of the usual shape.
 * The `megaelementsaddons_inactive_widgets` option holds a plain LIST of the
 * slugs that are switched OFF — not a slug => flag map — so a slug absent from
 * it is ON, and the empty array it defaults to means every widget is on. The
 * pack's registrar reads that option on each request and registers a widget only
 * when its slug is missing from the list. Both of its accessors are a bare
 * get_option and update_option with no memoised static in between, so a read
 * straight after a write is already current and this file has no reason to go
 * round them.
 *
 * Two things the pair below does deliberately. The inventory comes from
 * get_local_widgets_map() rather than get_widgets_map(), because the latter
 * prepends a `_mega_elements_addons_base` row that carries shared asset handles
 * and is not a widget at all; the pack's own settings screen drops that row for
 * the same reason. And the write changes the one entry it was asked about and
 * leaves every other alone, because the pack's own Save Changes rebuilds the
 * whole list from the widgets in the map at that moment — a widget the map does
 * not hold just then loses its stored off-state and comes back on.
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
 * The widget slugs this pack offers and whether each is switched on.
 *
 * @return array<string, bool>|WP_Error
 */
function nibwp_mega_elements_widget_state(): array|WP_Error
{
    $manager = 'MegaElementsAddonsForElementor\\Widgets_Manager';

    if (
        !class_exists($manager)
        || !method_exists($manager, 'get_local_widgets_map')
        || !method_exists($manager, 'get_inactive_widgets')
    ) {
        return new WP_Error(
            'nibwp_mega_elements_no_widget_list',
            __('This version of the add-on does not publish a list of widgets that can be switched off.', 'nibwp'),
            ['status' => 400]
        );
    }

    try {
        $map = \MegaElementsAddonsForElementor\Widgets_Manager::get_local_widgets_map();
    } catch (\Throwable $error) {
        // The map asks whether WooCommerce is active through a plain global
        // function from the pack's helpers file, which the pack loads one line
        // before this class. Load the class on its own — a profiler, an
        // autoloader, a half-finished update — and the call is a fatal Error
        // rather than a missing list. A refusal is the right answer; a fatal
        // here would take down whatever request asked.
        return new WP_Error(
            'nibwp_mega_elements_widget_list_failed',
            __('The add-on could not list its widgets on this request.', 'nibwp'),
            ['status' => 500]
        );
    }

    if (!is_array($map)) {
        return new WP_Error(
            'nibwp_mega_elements_no_widget_list',
            __('The add-on returned no widget list.', 'nibwp'),
            ['status' => 400]
        );
    }

    $off = \MegaElementsAddonsForElementor\Widgets_Manager::get_inactive_widgets();
    $off = is_array($off) ? array_map('strval', array_values($off)) : [];

    $state = [];

    foreach ($map as $slug => $widget) {
        $slug = (string) $slug;

        // ABSENT MEANS ON. The option lists what is switched off, so the test is
        // membership and not a value: there is no stored flag to cast, and a
        // cast of the slug string itself would report every widget as enabled.
        $state[$slug] = !in_array($slug, $off, true);
    }

    // A slug still in the option for a widget this install no longer maps — a
    // product widget after WooCommerce was switched off — is not reported. The
    // pack cannot register it either way, so offering it here would be offering
    // a switch that changes nothing an owner could see.
    return $state;
}

/**
 * Switch one widget on or off, in the pack's own storage format.
 */
function nibwp_mega_elements_set_widget(string $slug, bool $on): bool|WP_Error
{
    $state = nibwp_mega_elements_widget_state();

    if ($state instanceof WP_Error) {
        return $state;
    }

    if (!array_key_exists($slug, $state)) {
        return new WP_Error(
            'nibwp_mega_elements_unknown_widget',
            __('That widget is not one this add-on offers.', 'nibwp'),
            ['status' => 400]
        );
    }

    if (!method_exists('MegaElementsAddonsForElementor\\Widgets_Manager', 'save_inactive_widgets')) {
        return new WP_Error(
            'nibwp_mega_elements_readonly',
            __('This version of the add-on offers no way to store which widgets are switched off.', 'nibwp'),
            ['status' => 400]
        );
    }

    $off = \MegaElementsAddonsForElementor\Widgets_Manager::get_inactive_widgets();
    $off = is_array($off) ? array_map('strval', array_values($off)) : [];

    // One slug added or removed and every other left exactly as it was. Kept as
    // a list with sequential keys because that is what the pack stores and what
    // its registrar iterates, and because a gap in the keys turns the option
    // into an object the first time anything round-trips it through JSON.
    $off = $on
        ? array_values(array_diff($off, [$slug]))
        : array_values(array_unique(array_merge($off, [$slug])));

    \MegaElementsAddonsForElementor\Widgets_Manager::save_inactive_widgets($off);

    return true;
}

nibwp_elpack_register([
    'key' => 'mega-elements',
    'name' => 'Mega Elements - Addons for Elementor',
    'dir' => 'mega-elements-addons-for-elementor',
    // The constants are defined by the main plugin file itself, before it
    // requires anything else, so they hold even on the startup paths where the
    // pack bails out early and none of its classes have been loaded.
    'constants' => ['MEGA_ELEMENTS_ADDONS_FOR_ELEMENTOR_VERSION', 'MEGA_ELEMENTS_ADDONS_FOR_ELEMENTOR_FILE'],
    'classes' => ['MegaElementsAddonsForElementor\\Widgets_Manager', 'MegaElementsAddonsForElementor\\Mega_Elements_Addons_For_Elementor'],
    'description' => 'Manage this widget pack on this site: report its version, list the widgets it contributes to the Elementor editor, read one widget\'s full control '
        . 'schema so a layout can be built with it, and find which pages already use it. Switch any single one of its widgets on or off without disturbing the rest, and '
        . 'report which are off — the usual reason a section renders as nothing. The pack keeps no templates of its own, so the layouts its widgets sit in are ordinary '
        . 'posts and pages that the Elementor abilities handle.',
    'modules' => [
        'label' => 'widget',
        'read' => 'nibwp_mega_elements_widget_state',
        'write' => 'nibwp_mega_elements_set_widget',
    ],
    'notes' => [
        "A MODULE SLUG HERE IS THE WIDGET NAME. Every widget returns its own map key\n"
        . "from get_name(), so 'meafe-testimonial' is at once the slug list_modules\n"
        . "reports and the widget type list_widgets, describe_widget and find_usage\n"
        . "use. Pass the same string to all four. That is unusual enough among these\n"
        . "packs to be worth saying, and it means find_usage can answer exactly what a\n"
        . "disable_module would break, by name, before you run it.",

        "THE STORED STATE IS A LIST OF WHAT IS OFF, not a map of on and off. A slug\n"
        . "the option has never held is ON, and a site whose owner never opened the\n"
        . "add-on's Widgets screen has the option empty and every widget on.\n"
        . "list_modules resolves that already. Never write the option yourself:\n"
        . "enable_module and disable_module change one slug and leave the rest, while a\n"
        . "wholesale write is how a widget silently comes back on.",

        "THREE WIDGETS COME AND GO WITH WOOCOMMERCE: meafe-product-cat-tab,\n"
        . "meafe-product-cat-grid and meafe-product-grid are in the pack's map only\n"
        . "while WooCommerce is active. Deactivate WooCommerce and they vanish from\n"
        . "list_modules and from the editor while their stored state stays behind, so\n"
        . "enable_module refuses them by name until WooCommerce is back. The pack's own\n"
        . "Save Changes button does not keep them: it rebuilds the off-list from the\n"
        . "widgets mapped at that moment, so an owner who saves that screen with\n"
        . "WooCommerce off finds all three switched on when it returns. This ability\n"
        . "leaves them where they were.",

        "COUNT: 29 widgets always, plus those three, so 32 on a WooCommerce site. They\n"
        . "all file themselves under the single editor category meafe-elements, which\n"
        . "is registered on elementor/init — so a list_widgets from a front-end request\n"
        . "can come back short of what list_modules reports enabled, and that is\n"
        . "registry timing rather than a disabled widget.",

        "TEXT SETTINGS ARE REWRITTEN ON SAVE, and this one surprises people. The pack\n"
        . "hooks Elementor's own ajax endpoint ahead of Elementor and puts every\n"
        . "setting of its widgets whose key ends in _text or text_ through a plain text\n"
        . "sanitiser, so HTML written into one of those controls does not come back. It\n"
        . "only reaches widgets that are a direct child of a top-level element, which\n"
        . "in practice means a widget placed straight into a container: the same widget\n"
        . "inside a classic section and column is left untouched. So identical content\n"
        . "surviving in one place and not another is the pack, not a failed write.",

        "NO TEMPLATES OF ITS OWN: the pack registers no post type, so there is nothing\n"
        . "here to list, create or delete and no display conditions to set. Its widgets\n"
        . "are placed on ordinary posts and pages — use the Elementor abilities for\n"
        . "those.",
    ],
]);
