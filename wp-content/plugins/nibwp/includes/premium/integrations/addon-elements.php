<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: the Addon Elements widget and extension pack for Elementor.
 *
 * This pack registers no post type of its own — there is not one
 * register_post_type call in it, built from a variable or otherwise — so the
 * descriptor declares no templates and the engine offers no template actions.
 * The widgets that embed saved layouts (its modal popup, dropbar, content
 * switcher and data table) pick an ordinary `elementor_library` post, which the
 * Elementor integration already covers.
 *
 * What it does own is one on/off list: 59 modules on a default install, 57 of
 * them in the literal inside its own `Helper::get_eae_modules()` plus the two
 * that method always appends, covering the widgets it registers and five
 * site-wide extensions listed alongside them. The saved state is the
 * `wts_eae_elements` option, slug => the STRING 'true' or 'false', and a slug
 * ABSENT from it is ON — the pack fills an absent slug in with the bare `true`
 * it ships as every module's default.
 *
 * Two things the read below does deliberately. It takes the inventory from the
 * pack's Helper on the Plugin class's own static property rather than building
 * a second Helper, whose constructor would register the pack's ajax handlers a
 * second time for this request; and it then resolves each slug against a fresh
 * `get_option` of its own rather than trusting the state the pack merged in, so
 * a read straight after a write cannot hand back a pre-write value.
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
 * The pack's own Helper, or null.
 *
 * The pack keeps one on a public static property of its Plugin class, which is
 * what to reach for: Helper's constructor registers four of the pack's ajax
 * actions, so constructing a second one would register them all again for the
 * rest of this request.
 */
function nibwp_addon_elements_helper(): ?object
{
    if (!class_exists('WTS_EAE\\Plugin')) {
        return null;
    }

    $helper = \WTS_EAE\Plugin::$helper ?? null;

    return is_object($helper) ? $helper : null;
}

/**
 * Is one stored value an "on"?
 *
 * Not a cast. The pack stores the STRING 'false' for a module it switched off,
 * and 'false' is a non-empty string, so `(bool)` would report every disabled
 * module as enabled.
 *
 * The three clauses are the pack's module loader's own, clause for clause,
 * loose comparison included. That matters more than tidiness: the bare `true`
 * the pack uses as a default and the empty value an older version can leave
 * behind both load the module, so both have to read as on here. Report one of
 * them as off and disable_module answers "already off" about a widget that is
 * sitting in the editor.
 */
function nibwp_addon_elements_is_enabled(mixed $value): bool
{
    // Nothing the pack writes is an array or an object, and nothing loads from
    // one either — kept out of the comparisons below rather than cast.
    if (!is_scalar($value) && $value !== null) {
        return false;
    }

    return $value == 'true' || trim((string) $value) === '' || $value === null;
}

/**
 * The module slugs this pack offers and whether each is switched on.
 *
 * @return array<string, bool>|WP_Error
 */
function nibwp_addon_elements_module_state(): array|WP_Error
{
    $helper = nibwp_addon_elements_helper();

    if ($helper === null || !method_exists($helper, 'get_eae_modules')) {
        return new WP_Error(
            'nibwp_addon_elements_no_module_list',
            __('This version of the add-on does not publish a list of modules that can be switched off.', 'nibwp'),
            ['status' => 400]
        );
    }

    try {
        $modules = $helper->get_eae_modules();
    } catch (\Throwable $error) {
        // The pack's list asks WordPress whether WooCommerce is active and runs
        // two of its own filters on the way out, so a broken plugin elsewhere
        // can throw inside it. A refusal is the right answer; a fatal here would
        // take down whatever request asked.
        return new WP_Error(
            'nibwp_addon_elements_module_list_failed',
            __('The add-on could not list its modules on this request.', 'nibwp'),
            ['status' => 500]
        );
    }

    if (!is_array($modules)) {
        return new WP_Error(
            'nibwp_addon_elements_no_module_list',
            __('The add-on returned no module list.', 'nibwp'),
            ['status' => 400]
        );
    }

    $saved = get_option('wts_eae_elements', []);
    $saved = is_array($saved) ? $saved : [];

    $state = [];

    foreach ($modules as $slug => $module) {
        $slug = (string) $slug;

        // ABSENT MEANS ON: a slug the option has never held takes the pack's own
        // default, which is `true` for every module it ships.
        $state[$slug] = array_key_exists($slug, $saved)
            ? nibwp_addon_elements_is_enabled($saved[$slug])
            : nibwp_addon_elements_is_enabled(is_array($module) ? ($module['enabled'] ?? true) : true);
    }

    // A slug left in the option for a module this version no longer lists — a
    // WooCommerce widget after WooCommerce was switched off, or one a release
    // dropped — is not reported. The pack ignores any key outside its own list,
    // so offering it here would be offering a switch that changes nothing.
    return $state;
}

/**
 * Switch one module on or off, in the pack's own storage format.
 */
function nibwp_addon_elements_set_module(string $slug, bool $on): bool|WP_Error
{
    $state = nibwp_addon_elements_module_state();

    if ($state instanceof WP_Error) {
        return $state;
    }

    if (!array_key_exists($slug, $state)) {
        return new WP_Error(
            'nibwp_addon_elements_unknown_module',
            __('That module is not one this add-on offers.', 'nibwp'),
            ['status' => 400]
        );
    }

    $saved = get_option('wts_eae_elements', []);
    $saved = is_array($saved) ? $saved : [];

    // One key changed and every other key left exactly as it was. The strings
    // rather than booleans because that is what the pack writes and what its
    // module loader compares against, and the merge rather than a fresh array
    // because the pack's own settings screen writes all of them at once — an
    // omitted key would read as on and quietly switch a module back on.
    $saved[$slug] = $on ? 'true' : 'false';

    update_option('wts_eae_elements', $saved);

    return true;
}

nibwp_elpack_register([
    'key' => 'addon-elements',
    'name' => 'Addon Elements for Elementor',
    'dir' => 'addon-elements-for-elementor-page-builder',
    'constants' => ['EAE_VERSION', 'EAE_PLUGIN_BASE'],
    'classes' => ['WTS_EAE\\Plugin', 'WTS_EAE\\Classes\\Helper'],
    'description' => 'Manage this element pack on this site: report its version, list the widgets it contributes to the Elementor editor, read one widget\'s full '
        . 'control schema, and find which pages are built with it. Switch any single one of its modules — the widgets and the site-wide extensions it lists together '
        . 'and stores together — on or off without disturbing the rest, and report which are off, the usual reason a section renders as nothing.',
    'modules' => [
        'label' => 'module',
        'read' => 'nibwp_addon_elements_module_state',
        'write' => 'nibwp_addon_elements_set_module',
    ],
    'notes' => [
        "NO TEMPLATES OF ITS OWN: this pack registers no post type, so there is\n"
        . "nothing here to list, create or delete. Its modal popup, dropbar, content\n"
        . "switcher and data table embed a saved layout by picking an ordinary\n"
        . "Elementor library template — use the Elementor abilities for those.",

        "SLUGS ARE NOT WIDGET NAMES: list_modules reports the pack's own module\n"
        . "slugs and list_widgets reports Elementor widget types, and the two are not\n"
        . "the same string. The pack uses two prefixes with no rule for which —\n"
        . "'timeline' registers 'eae-timeline' but 'flip-box' registers 'wts-flipbox',\n"
        . "'post-list' registers 'wts-postlist' — so the hyphens move as well. Pair\n"
        . "the two lists up by reading both before switching anything off; guessing a\n"
        . "prefix gets a refusal at best and the wrong widget at worst.",

        "THE LIST IS WIDER THAN WIDGETS: five of the entries are site-wide\n"
        . "extensions and not widgets at all — particles, wrapper-links, bg-slider,\n"
        . "animated-gradient and elementor-form-action. They add controls to sections\n"
        . "and containers the theme already has, so switching one off changes pages\n"
        . "that list_widgets and find_usage cannot point at.",

        "PAID-ONLY MODULES ARE STILL IN THE LIST: 25 of them are marked as belonging\n"
        . "to the pack's paid build, and on a site without it the module loader skips\n"
        . "them whatever their stored state. list_modules will report one as enabled\n"
        . "and no widget will appear, which is not a fault to chase.",

        "DEFAULTS AND WRITES: the saved option holds only the modules whose state\n"
        . "was set, so a slug missing from it is ON, not off. list_modules resolves\n"
        . "that already. Never write the option yourself: a partial write is fine\n"
        . "but a wholesale one drops the keys it omits, and each dropped key comes\n"
        . "back on. enable_module and disable_module change one key and leave the\n"
        . "rest, so they agree with the pack's own settings screen either way.",

        "TWO MODULES COME AND GO: woo-products and woo-category are in the list only\n"
        . "while WooCommerce is active. Deactivate WooCommerce and they vanish from\n"
        . "list_modules while their stored state stays behind, so enable_module\n"
        . "refuses them by name until WooCommerce is back.",

        "API KEYS ARE NOT MODULES: with no key in the wts_eae_gmap_key option the\n"
        . "Google Map widget's render returns early — nothing at all on the front end,\n"
        . "and a warning only inside the editor. So a blank map while list_modules\n"
        . "reports gmap enabled is a missing key, not a disabled module. The pack keeps\n"
        . "a second key, wts_eae_youtube_api_key, for the YouTube feed widget its paid\n"
        . "build adds. Neither is a toggle and neither is settable from here: they are\n"
        . "on the add-on's own Configuration tab, at admin.php?page=eae-settings — the\n"
        . "link the editor warning offers points at page=eae and goes nowhere.",

        "THE PAID BUILD IS THE SAME CODE IN A DIFFERENT DIRECTORY, and it deactivates\n"
        . "this one on sight. On such a site the constants and classes this\n"
        . "integration looks for are all present, so the pack reads as active and the\n"
        . "module list is correct, but info reports no version and list_widgets comes\n"
        . "back empty — widget provenance is the plugin directory a widget's class was\n"
        . "declared in, and on that site it is the other one.",
    ],
]);
