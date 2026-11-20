<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: the Move widget pack for Elementor.
 *
 * No templates. There is not one register_post_type call anywhere in the pack,
 * so the descriptor declares none and the engine offers no template actions. Its
 * Remote Template widget does output a saved template by id, but that is an
 * ordinary Elementor library template and the Elementor abilities cover it.
 *
 * TWO SWITCHABLE LISTS, AND ONLY ONE OF THEM IS WIRED. htmove_widget_list holds
 * slug => bool for the widgets the pack registers, and its own registrar includes
 * a widget's class only when that value resolves to exactly true — so it is the
 * list that decides what an agent can build with, and it is the one below.
 * htmove_module_list has the identical shape for three feature toggles,
 * crossdomaincp, megamenubuilder and customcss: each is marked is_pro with a
 * default of off, the feature itself lives in the paid plugin, and nothing in
 * this build reads the option except the checkbox that renders it disabled. So
 * disable_module does not reach it, and the two are deliberately not merged —
 * they are separate options, and a slug that ever appeared in both would be
 * written to whichever store the merge happened to prefer.
 *
 * POLARITY IS DIRECT AND THE DEFAULT IS ON: true means the widget is registered,
 * and a slug the option has never held falls back to the pack's own 'enable'
 * flag, which is true for every widget in this build. An option that has never
 * been written therefore means every widget is on, not none of them.
 *
 * The state is rebuilt from get_option() and the pack's own widget list rather
 * than through its move_addons_get_option_data() helper. Not because that helper
 * caches — it does not — but because the gate deciding registration compares the
 * stored value with === true, and mirroring that test here is the only way
 * list_modules reports what the editor will really do with a value the pack's own
 * screen never writes. The widget list is rebuilt on each call and reads no
 * option of its own, so it cannot answer a read after a write with a stale value.
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
 * Every widget slug the pack offers, and what it defaults to.
 *
 * Asked of the widget controller rather than of the pack's admin options class,
 * which only delegates to it and is included on admin requests alone — a REST
 * call would find nothing there. The controller is loaded on every request,
 * builds its list fresh on each call and consults no option, so this is both
 * available and safe to read straight after a write.
 *
 * @return array<string, bool>|WP_Error
 */
function nibwp_move_addons_widget_defaults(): array|WP_Error
{
    $control = 'MoveAddons\Elementor\Widgets_Control';

    if (!class_exists($control) || !method_exists($control, 'instance')) {
        return new WP_Error(
            'nibwp_move_addons_no_widget_list',
            __('This version of the add-on does not publish a list of widgets that can be switched off.', 'nibwp'),
            ['status' => 400]
        );
    }

    $instance = call_user_func([$control, 'instance']);

    if (!is_object($instance) || !method_exists($instance, 'widgets')) {
        return new WP_Error(
            'nibwp_move_addons_no_widget_list',
            __('This version of the add-on does not publish a list of widgets that can be switched off.', 'nibwp'),
            ['status' => 400]
        );
    }

    $defaults = [];

    foreach ((array) $instance->widgets() as $slug => $widget) {
        if ((string) $slug === '') {
            continue;
        }

        // 'enable' is what the pack itself passes as the fallback for a slug its
        // option has never held, so it is the default in the engine's sense too.
        $defaults[(string) $slug] = (bool) (is_array($widget) ? ($widget['enable'] ?? false) : false);
    }

    if ($defaults === []) {
        return new WP_Error(
            'nibwp_move_addons_no_widget_list',
            __('This add-on lists no widgets to switch on this request.', 'nibwp'),
            ['status' => 400]
        );
    }

    return $defaults;
}

/**
 * The widget slugs this pack offers and whether each is switched on.
 *
 * @return array<string, bool>|WP_Error
 */
function nibwp_move_addons_widget_state(): array|WP_Error
{
    $defaults = nibwp_move_addons_widget_defaults();

    if ($defaults instanceof WP_Error) {
        return $defaults;
    }

    $saved = get_option('htmove_widget_list', []);
    $saved = is_array($saved) ? $saved : [];

    $state = [];

    foreach ($defaults as $slug => $default) {
        if (!array_key_exists($slug, $saved)) {
            $state[$slug] = $default;
            continue;
        }

        $stored = $saved[$slug];

        // Two halves of the pack's own read, mirrored exactly: it unwraps an
        // 'enable' member when the stored value is an array, and its registrar
        // then tests the result with === true. So a slug stored as 1 or 'on'
        // rather than a real boolean reads as OFF here, because that is what the
        // editor does with it.
        $stored = is_array($stored) ? ($stored['enable'] ?? null) : $stored;

        $state[$slug] = $stored === true;
    }

    // A slug the option holds that the pack's list no longer offers is left out
    // on purpose. The registrar walks the pack's list and never looks at a key it
    // does not have, so such an entry changes nothing — reporting it would let
    // enable_module answer that it had switched on a widget which will not
    // appear. It goes live again by itself if the list regains the slug.

    return $state;
}

/**
 * Switch one widget on or off, in the pack's own storage format.
 */
function nibwp_move_addons_set_widget(string $slug, bool $on): bool|WP_Error
{
    $state = nibwp_move_addons_widget_state();

    if ($state instanceof WP_Error) {
        return $state;
    }

    if (!array_key_exists($slug, $state)) {
        return new WP_Error(
            'nibwp_move_addons_unknown_widget',
            __('That widget is not one this add-on offers.', 'nibwp'),
            ['status' => 400]
        );
    }

    // Read the option again rather than write the resolved state back: every
    // other key has to go down as it was, including one stored in a shape the
    // read above flattened to a boolean.
    $saved = get_option('htmove_widget_list', []);
    $saved = is_array($saved) ? $saved : [];

    // A real boolean, not 1 and not 'on': the pack compares with === true, so
    // anything else switches the widget off however it reads.
    $saved[$slug] = $on;

    update_option('htmove_widget_list', $saved);

    return true;
}

nibwp_elpack_register([
    'key' => 'move-addons',
    'name' => 'Move Addons for Elementor',
    'dir' => 'move-addons',
    'constants' => ['MOVE_ADDONS_VERSION', 'MOVE_ADDONS_FILE'],
    'classes' => ['MoveAddons\Elementor\Base', 'MoveAddons\Elementor\Widgets_Control'],
    'functions' => ['move_addons_get_option_data'],
    'description' => 'Manage this widget pack on this site: report its version, list every widget it contributes to the Elementor editor, read one widget\'s full '
        . 'control schema before building with it, find which pages already use it, and switch any single widget off or back on — a widget switched off is not '
        . 'registered at all, which is the usual reason a section renders as nothing. It registers no post type, so there is no template library here, and its '
        . 'separate list of paid feature modules is not switchable from this build.',
    'modules' => [
        'label' => 'widget',
        'read' => 'nibwp_move_addons_widget_state',
        'write' => 'nibwp_move_addons_set_widget',
    ],
    'notes' => [
        "NO TEMPLATES OF ITS OWN: the pack registers no post type and keeps no\n"
        . "template library, so there is nothing here to list, create or delete. Its\n"
        . "Remote Template widget takes the id of a saved Elementor template and\n"
        . 'outputs it; those are ordinary library templates and belong to Elementor.',

        "SLUGS ARE NOT THE WIDGET NAMES: a slug from list_modules is the pack's own\n"
        . "folder name, such as accordion, and the Elementor name from list_widgets\n"
        . "is almost always that with move- in front. Two do not follow the rule at\n"
        . "all: the slug cf7 is the widget move-contact-form7, and the slug\n"
        . "dual-color-heading is the widget move-doul-color-heading, spelled that way\n"
        . "in the pack. So pass a slug to enable_module and disable_module, and a\n"
        . 'name from list_widgets to describe_widget and find_usage.',

        "A WIDGET SWITCHED OFF IS NOT REGISTERED AT ALL: the pack skips including\n"
        . "its class, so it vanishes from list_widgets and from the editor while\n"
        . "every page already using it keeps it in its saved data and renders that\n"
        . "section as nothing. Run find_usage before switching one off, and\n"
        . 're-enable rather than rebuild.',

        "ABSENT MEANS ON: until the owner saves the pack's Widgets screen once, the\n"
        . "option behind this holds nothing at all and every widget is on. That one\n"
        . "save writes every slug together, so from then on a widget is off only\n"
        . 'because someone switched it off.',

        "THE LIST IS SHORTER ON SOME SITES: the job-manager slug only enters it when\n"
        . "WP Job Manager is installed and shop-product-grid only when WooCommerce\n"
        . "is, so a slug missing from list_modules on one site is not necessarily\n"
        . 'missing from the pack.',

        "THE PAID WIDGETS ARE NOT IN THIS LIST: the pack's own Pro Widgets tab\n"
        . "advertises widgets that exist only in its paid plugin, and none of them is\n"
        . "switchable here, so enable_module refuses them by name. Nothing in the free\n"
        . "list is marked as paid either; if a version or a filter ever marks one, the\n"
        . 'pack renders that row locked on its own screen while this still writes it.',

        "A SECOND ON/OFF LIST IS NOT REACHED FROM HERE: the pack's Modules screen\n"
        . "switches three features — cross-domain copy and paste, a mega menu builder\n"
        . "and custom CSS — in a separate option of its own. All three are paid\n"
        . "features, off by default, and nothing in this build reads that option, so\n"
        . "list_modules and disable_module cover the widget list only. Switch those\n"
        . 'from the add-on\'s own Modules screen.',

        "THE MAILCHIMP WIDGET IS CONFIGURED ELSEWHERE: it reads an API key the pack\n"
        . "keeps in a third option, written from its User Data screen, and without one\n"
        . "the widget offers no list to choose and describe_widget reports a notice\n"
        . 'control where the list would be. Set the key in wp-admin first.',
    ],
]);
