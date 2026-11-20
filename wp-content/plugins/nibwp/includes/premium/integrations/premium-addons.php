<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: the Premium Addons widget and extension pack for Elementor.
 *
 * This pack registers no post type of its own — there is not one
 * register_post_type call in it — so the descriptor declares no templates and
 * the engine offers no template actions. Its template library inserts ordinary
 * `elementor_library` posts, which the Elementor integration already covers.
 *
 * What it does own is one long on/off list: 122 keys in 4.11.109, covering the
 * widgets it registers, site-wide extensions such as the scroll effects and the
 * duplicator, and a `svg_<key>` sub-toggle for each widget that can draw an SVG.
 * The default list is admin/includes/keys.php, reached through the plugin's own
 * Admin_Helper::get_elements_keys(); the saved state is the `pa_save_settings`
 * option, which holds ONLY the keys that are on, each as true. A key missing
 * from it is therefore off — but only once the option exists: before the owner's
 * first save there is no option at all and everything is on bar the two opt-ins,
 * `premium-ai-abilities` and `pa_mc_temp`.
 *
 * The read below rebuilds that from the option and the default list rather than
 * calling get_enabled_elements(), which memoises into a public static AND into
 * the object cache for an hour, so a read straight after a write hands back the
 * pre-write value.
 *
 * Nothing here includes keys.php itself. The plugin loads it with require_once
 * and keeps the return value, so a second require_once of that file anywhere in
 * the request would hand the plugin `true` in place of its own element list.
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
 * The keys the plugin ships switched off.
 *
 * Everything else defaults to on, so these two need the opposite treatment on
 * the way in as well: present in the option counts only if the stored value is
 * truthy, where for every other key mere presence means on.
 *
 * @return array<int, string>
 */
function nibwp_premium_addons_opt_in_keys(): array
{
    return ['pa_mc_temp', 'premium-ai-abilities'];
}

/**
 * The pack's default on/off list, built the way the pack builds it.
 *
 * @return array<string, bool>|WP_Error
 */
function nibwp_premium_addons_defaults(): array|WP_Error
{
    $helper = 'PremiumAddons\Admin\Includes\Admin_Helper';

    if (!class_exists($helper) || !method_exists($helper, 'get_elements_keys')) {
        return new WP_Error(
            'nibwp_premium_addons_no_list',
            __('This version of Premium Addons does not expose the list of widgets and extensions it can switch off.', 'nibwp'),
            ['status' => 400]
        );
    }

    $elements = $helper::get_elements_keys();

    // The accessor hands back whatever require_once gave it, so anything but an
    // array means keys.php was already included somewhere else this request.
    if (!is_array($elements)) {
        return new WP_Error(
            'nibwp_premium_addons_no_list',
            __('Premium Addons returned no usable list of widgets and extensions.', 'nibwp'),
            ['status' => 500]
        );
    }

    $defaults = [];

    foreach ($elements as $element) {
        if (!is_array($element)) {
            continue;
        }

        $key = (string) ($element['key'] ?? '');

        if ($key === '') {
            continue;
        }

        $defaults[$key] = true;

        // A widget that can draw an SVG carries a second key for that control
        // group alone. Switching it off leaves the widget itself in place.
        if (isset($element['draw_svg'])) {
            $defaults['svg_' . $key] = true;
        }
    }

    if ($defaults === []) {
        return new WP_Error(
            'nibwp_premium_addons_no_list',
            __('Premium Addons listed no widgets or extensions to switch.', 'nibwp'),
            ['status' => 500]
        );
    }

    // The mini-cart template takeover is not in keys.php at all; the plugin adds
    // it here, so this is also where it comes into existence for us.
    foreach (nibwp_premium_addons_opt_in_keys() as $opt_in) {
        $defaults[$opt_in] = false;
    }

    return $defaults;
}

/**
 * Every slug the pack can switch, and whether it is on right now.
 *
 * @return array<string, bool>|WP_Error
 */
function nibwp_premium_addons_module_state(): array|WP_Error
{
    $defaults = nibwp_premium_addons_defaults();

    if ($defaults instanceof WP_Error) {
        return $defaults;
    }

    $saved = get_option('pa_save_settings', null);

    // No stored option is the state of a site whose owner never opened the
    // settings screen, and it is not "everything off": the plugin falls back to
    // its own defaults, which is everything on bar the two opt-ins.
    if (!is_array($saved)) {
        return $defaults;
    }

    $opt_in = nibwp_premium_addons_opt_in_keys();
    $state = [];

    foreach ($defaults as $slug => $default) {
        if (in_array($slug, $opt_in, true)) {
            $state[$slug] = isset($saved[$slug]) && (bool) $saved[$slug];
            continue;
        }

        // Presence, not value. The plugin writes only the keys that are on and
        // its own reader tests isset(), so a hand-edited `false` counts as on
        // there and has to count as on here or the two disagree about the site.
        $state[$slug] = isset($saved[$slug]);
    }

    return $state;
}

/**
 * Switch one slug on or off, through the plugin's own settings service.
 */
function nibwp_premium_addons_set_module(string $slug, bool $on): bool|WP_Error
{
    $helper = 'PremiumAddons\Admin\Includes\Admin_Helper';

    if (!class_exists($helper) || !method_exists($helper, 'update_elements_settings')) {
        return new WP_Error(
            'nibwp_premium_addons_readonly',
            __('This version of Premium Addons can only have its widgets switched from its own settings screen.', 'nibwp'),
            ['status' => 400]
        );
    }

    $state = nibwp_premium_addons_module_state();

    if ($state instanceof WP_Error) {
        return $state;
    }

    if (!array_key_exists($slug, $state)) {
        return new WP_Error(
            'nibwp_premium_addons_unknown_element',
            __('That is not one of the widgets or extensions this add-on can switch.', 'nibwp'),
            ['status' => 400]
        );
    }

    // The service merges onto what get_enabled_elements() reads back, and that
    // cached copy outlives the request wherever a persistent object cache is
    // installed. Clearing both of its caches first means the merge starts from
    // the option, so it cannot revive a slug that was switched off elsewhere.
    wp_cache_delete('pa_elements', 'premium_addons');

    if (property_exists($helper, 'enabled_elements')) {
        $helper::$enabled_elements = null;
    }

    // Its own writer, so the other 121 keys, the derived list of global features
    // and the generated per-page CSS and JS files are all dealt with. Writing
    // pa_save_settings by hand would leave the last two behind.
    $result = $helper::update_elements_settings([$slug => $on]);

    if (!is_array($result) || ($result['updated'] ?? []) === []) {
        return new WP_Error(
            'nibwp_premium_addons_write_failed',
            __('Premium Addons did not accept the change to that widget.', 'nibwp'),
            ['status' => 500]
        );
    }

    return true;
}

nibwp_elpack_register([
    'key' => 'premium-addons',
    'name' => 'Premium Addons for Elementor',
    'dir' => 'premium-addons-for-elementor',
    'constants' => ['PREMIUM_ADDONS_VERSION'],
    'classes' => ['PremiumAddons\Includes\PA_Core', 'PremiumAddons\Admin\Includes\Admin_Helper'],
    'description' => 'Manage Premium Addons for Elementor on this site: report its version, list every widget it contributes to the Elementor registry, '
        . 'and switch any single one of its widgets, site-wide extensions or per-widget SVG-draw options on or off without disturbing the rest. '
        . 'Also reports which of them are currently off, which is usually the reason a section renders as nothing.',
    'modules' => [
        'label' => 'element',
        'read' => 'nibwp_premium_addons_module_state',
        'write' => 'nibwp_premium_addons_set_module',
    ],
    'notes' => [
        "NO TEMPLATES OF ITS OWN: this pack registers no post type, so there is\n"
        . "nothing here to list, create or delete. Its template library inserts\n"
        . "ordinary Elementor library templates — use the Elementor abilities for\n"
        . 'those.',
        "SLUGS ARE NOT WIDGET NAMES: list_modules reports the pack's own setting\n"
        . "keys and list_widgets reports Elementor widget types, and for nearly\n"
        . "every widget the two differ — the 'premium-blog' setting switches the\n"
        . "'premium-addon-blog' widget. Pair them up by hand before switching\n"
        . 'anything, rather than assuming the names match.',
        "THE LIST IS WIDER THAN WIDGETS: only about a third of the slugs are\n"
        . "widgets. The rest are site-wide extensions — scroll effects, the\n"
        . "duplicator, display conditions, the dynamic asset generator — and one\n"
        . "'svg_<slug>' per widget that can draw an SVG, which controls that group\n"
        . "of settings and not the widget. Slugs for widgets only the paid add-on\n"
        . 'registers are in the list either way, and switching one on without it does nothing.',
        "premium-ai-abilities IS NOT A WIDGET: switching it on stands up the\n"
        . "pack's own MCP server on this site. Leave it alone unless the owner\n"
        . 'asked for it by name.',
        "DEFAULTS: until the owner saves the pack's settings screen once there is\n"
        . "no stored setting at all and everything is on except premium-ai-abilities\n"
        . "and pa_mc_temp. After that the store holds only what is on, so a slug\n"
        . "missing from it is off. list_modules resolves both cases already — do not\n"
        . "read that option yourself, and never write it directly: a partial write\n"
        . 'switches off everything it omits and leaves the generated assets stale.',
    ],
]);
