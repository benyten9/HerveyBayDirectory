<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: the LA-Studio Element Kit widget pack for Elementor.
 *
 * No template library. The pack registers three post types and not one of them
 * is a template: la_portfolio, la_album and la_event are ordinary public content
 * types, each behind its own switch on the pack's Available Addons screen and
 * each off until the owner turns it on. A portfolio entry is content, so the
 * Elementor and WordPress abilities already cover it, and calling one a template
 * here would put create_template and delete_template on the owner's real posts.
 *
 * The pack does ship a theme builder — header, footer, single, archive, 404,
 * search results and popups — but it builds those on Elementor's OWN
 * elementor_library post type, as document types it registers, with display
 * rules in _elementor_conditions. Declaring elementor_library here would claim
 * every saved Elementor template on the site, including Elementor's own and
 * every other pack's, so the descriptor declares no template post type at all
 * and the theme builder is described in the notes instead.
 *
 * What the pack does own is one widget on/off list. It is not an option of its
 * own: every setting the pack has lives in the single lastudio-kit-settings
 * option, and the widget list is the avaliable_widgets key inside it — the
 * misspelling is the pack's and is the real key. It maps slug => 'true' |
 * 'false', and the gate reads it through filter_var(), so '1' and '' behave the
 * same as 'true' and 'false'.
 *
 * Two things about that list matter more than its shape. Its slugs are the KEYS
 * of the pack's own widget registry, which is what the pack gates registration
 * on — not the 'slug' field inside each registry row, which nothing in this
 * version reads and which is wrong for two of the widgets it describes. And an
 * EMPTY map means every widget loads, not none of them: the pack falls back to
 * loading the lot when the owner has never saved that screen. So the write below
 * states the whole map on that first write — putting one slug into an empty map
 * would switch off the other fifty-two along with it.
 *
 * The read goes to the option and to the registry file, never through
 * lastudio_kit_settings()->get_option(), which memoises the whole settings array
 * into an instance property on first call and would answer a read after a write
 * with the value it cached.
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
 * The pack's own widget registry, keyed by the slug its on/off list uses.
 *
 * Read from the file the pack itself requires on every editor request, rather
 * than from the settings singleton's widget list, so it does not depend on that
 * singleton having initialised and is the same list the pack gates registration
 * with.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lastudio_kit_widget_registry(): array|WP_Error
{
    $refusal = new WP_Error(
        'nibwp_lastudio_kit_no_widget_list',
        __('This version of the add-on does not publish a list of widgets that can be switched off.', 'nibwp'),
        ['status' => 400]
    );

    if (!function_exists('lastudio_kit')) {
        return $refusal;
    }

    $kit = lastudio_kit();

    // plugin_path() is the pack's own answer for where it is installed, worth
    // asking rather than guessing for a plugin that was symlinked or relocated.
    // It memoises only that directory, so unlike the settings reader it cannot
    // hand back a stale module state.
    if (!is_object($kit) || !method_exists($kit, 'plugin_path')) {
        return $refusal;
    }

    $file = (string) $kit->plugin_path('includes/addons/widget-registry.php');

    if ($file === '' || !is_readable($file)) {
        return $refusal;
    }

    // include, not require_once: the file is a plain `return [...]`, and a _once
    // form hands the second call in the same request `true` instead of the array
    // — which is every write, because a write reads the list first.
    $registry = include $file;

    if (!is_array($registry) || $registry === []) {
        return $refusal;
    }

    return $registry;
}

/**
 * Every setting the pack has, exactly as stored.
 *
 * @return array<string, mixed>
 */
function nibwp_lastudio_kit_settings(): array
{
    $settings = get_option('lastudio-kit-settings', []);

    return is_array($settings) ? $settings : [];
}

/**
 * The widget map as stored: slug => 'true' | 'false'.
 *
 * Empty until the owner saves the pack's Available Addons screen once, and empty
 * means every widget is registered rather than none of them.
 *
 * @return array<string, mixed>
 */
function nibwp_lastudio_kit_widget_map(): array
{
    $map = nibwp_lastudio_kit_settings()['avaliable_widgets'] ?? [];

    return is_array($map) ? $map : [];
}

/**
 * The widget slugs this pack offers and whether each is switched on.
 *
 * @return array<string, bool>|WP_Error
 */
function nibwp_lastudio_kit_widget_state(): array|WP_Error
{
    $registry = nibwp_lastudio_kit_widget_registry();

    if ($registry instanceof WP_Error) {
        return $registry;
    }

    $map = nibwp_lastudio_kit_widget_map();

    // The pack's own gate, made the same way here: a slug is on when its stored
    // value reads as true, or when the map is empty altogether — the fresh
    // install, where the pack ignores the map and loads everything.
    $never_saved = $map === [];
    $state = [];

    // Only the registry's own slugs. A slug left in the map that this version's
    // registry no longer has is inert, because the pack walks the registry and
    // never the map, so offering it as a switch would offer one that does
    // nothing.
    foreach (array_keys($registry) as $slug) {
        $slug = (string) $slug;
        $state[$slug] = $never_saved || filter_var($map[$slug] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    return $state;
}

/**
 * Switch one widget on or off, in the pack's own storage format.
 */
function nibwp_lastudio_kit_set_widget(string $slug, bool $on): bool|WP_Error
{
    $state = nibwp_lastudio_kit_widget_state();

    if ($state instanceof WP_Error) {
        return $state;
    }

    if (!array_key_exists($slug, $state)) {
        return new WP_Error(
            'nibwp_lastudio_kit_unknown_widget',
            __('That widget is not one this add-on offers.', 'nibwp'),
            ['status' => 400]
        );
    }

    $map = nibwp_lastudio_kit_widget_map();

    // An empty map means "load everything", so putting one slug into it would
    // switch off every widget the map does not name. The first write has to
    // state the whole list, which is what the pack's own screen does when the
    // owner saves it for the first time.
    if ($map === []) {
        foreach ($state as $other => $enabled) {
            $map[(string) $other] = $enabled ? 'true' : 'false';
        }
    }

    // The strings rather than booleans: this is what the pack's own switcher
    // stores, and its gate is a filter_var() read that accepts either.
    $map[$slug] = $on ? 'true' : 'false';

    // The whole settings array goes back with only this key changed. The pack
    // keeps everything from API keys to custom fonts in the same option, and its
    // own REST endpoint merges into it for the same reason.
    $settings = nibwp_lastudio_kit_settings();
    $settings['avaliable_widgets'] = $map;

    update_option('lastudio-kit-settings', $settings);

    return true;
}

nibwp_elpack_register([
    'key' => 'lastudio-kit',
    'name' => 'LA-Studio Element Kit for Elementor',
    'dir' => 'lastudio-element-kit',
    // The pack defines no constant of its own — not one define() outside its
    // AJAX handler's WordPress flags — so the markers are its main class and the
    // accessor beside it, both declared as its plugin file loads, plus the
    // settings pair it loads once Elementor is there.
    'classes' => ['LaStudio_Kit', 'LaStudio_Kit_Settings'],
    'functions' => ['lastudio_kit', 'lastudio_kit_settings'],
    'description' => 'Manage this widget pack on this site: report its version, list every widget it contributes to the Elementor editor, read one widget\'s full '
        . 'control schema, find the pages built with it, and switch any single widget off or back on. It keeps no template library of its own — its theme builder '
        . 'builds on Elementor\'s own saved templates — so this is inventory, usage and the widget on/off list rather than a template library.',
    'modules' => [
        'label' => 'widget',
        'read' => 'nibwp_lastudio_kit_widget_state',
        'write' => 'nibwp_lastudio_kit_set_widget',
    ],
    'notes' => [
        "NO TEMPLATE LIBRARY OF ITS OWN, so there is nothing here to list, create\n"
        . "or delete. The pack's theme builder is real — header, footer, single,\n"
        . "archive, 404, search results and popups — but it builds them as document\n"
        . "types on Elementor's own elementor_library post type, with display rules\n"
        . "in the _elementor_conditions meta, so they are Elementor library\n"
        . "templates and the Elementor abilities cover them. Two notes on that\n"
        . "builder: it stands down completely when Elementor Pro is active, leaving\n"
        . "Pro's own theme builder in charge, and a mega menu is built on the NAV\n"
        . 'MENU ITEM post itself rather than on a template.',

        "SLUGS ARE NOT THE WIDGET NAMES: list_modules reports the keys of the\n"
        . "pack's widget registry and list_widgets reports Elementor widget names,\n"
        . "so the same widget is 'button' in one and 'lakit-button' in the other.\n"
        . "That 'lakit-' prefix is the whole difference for 51 of the 53 widgets in\n"
        . "1.6.3; the two that break it are login, whose widget is lakit-login-frm,\n"
        . "and register, whose widget is lakit-register-frm. Pair the lists by that\n"
        . 'rule, and check a slug you are about to switch rather than assuming it.',

        "EVERYTHING IS ON UNTIL THE SCREEN IS SAVED ONCE. The pack stores the\n"
        . "widgets that are on, and treats an empty list as nobody having\n"
        . "configured it — so it loads every widget. list_modules reports that\n"
        . "resolved state, which is why a fresh install comes back with all 53 on.\n"
        . "The first disable_module from here writes the full list out first, so\n"
        . "switching one widget off leaves the other fifty-two exactly where they\n"
        . 'were. After that a slug missing from the list is off.',

        "A WIDGET SWITCHED OFF IS NOT REGISTERED AT ALL: the pack skips its class,\n"
        . "so it disappears from the editor and from list_widgets, while every page\n"
        . "already built with it keeps it in its saved data and renders that section\n"
        . "as nothing. Run find_usage before switching one off, and re-enable\n"
        . 'rather than rebuild.',

        "SOME OF ITS WIDGETS CANNOT BE SWITCHED FROM HERE: the WooCommerce widgets\n"
        . "and the Contact Form 7 widget are registered whenever the plugin they are\n"
        . "for is active, whatever the on/off list says, and the portfolio, album and\n"
        . "event widgets come with the extensions below. They appear in list_widgets\n"
        . 'and are absent from list_modules, which is correct.',

        "ITS OTHER ON/OFF LIST IS NOT EXPOSED HERE. Beside the widgets the pack\n"
        . "keeps ten extension switches in the same option under its own key: six\n"
        . "editor extensions (motion effects, floating effects, CSS transform,\n"
        . "wrapper links, element visibility, custom CSS), a switch for the default\n"
        . "WordPress widgets, and three that register the la_portfolio, la_album and\n"
        . "la_event content types. They are deliberately left out because the\n"
        . "absent-value default is not the same for all of them — the six editor\n"
        . "extensions are on when their key is absent and the other four are off —\n"
        . "and one list cannot say both. Switch those on the add-on's own Available\n"
        . 'Addons screen.',
    ],
]);
