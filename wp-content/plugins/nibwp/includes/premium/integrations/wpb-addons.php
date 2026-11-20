<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: the WPB Elementor Addons widget pack.
 *
 * Fifteen widgets, a switch for each of them, and no templates anywhere.
 *
 * The switch list is real, and it is the one thing to get right about this pack.
 * Every one of the fifteen register calls sits behind wpb_ea_enable_addons(),
 * which reads the `wpb_ea_addons` option and compares the stored value against
 * the string 'on' — so a switched-off element is never handed to Elementor at
 * all. Two things about that storage decide how it has to be read. The value is
 * the string 'on' or the string 'off', because the settings form posts a hidden
 * 'off' ahead of each checkbox; 'off' is a non-empty string, so a bool cast would
 * report every switched-OFF element as on. And a key the option does not mention
 * is ON, because 'on' is the default the pack itself passes at all fifteen call
 * sites — reading a missing key as off would report the opposite of what the
 * owner sees in the editor.
 *
 * There is no template post type. register_post_type appears nowhere in the pack,
 * not even behind a variable. The one template-shaped helper it carries lists
 * Elementor's own elementor_library posts and has no callers at all, so there is
 * nothing here to declare and nothing to manage except through the Elementor
 * abilities.
 *
 * The three colour names in this plugin are not a module list either. They are
 * keys inside a separate `wpb_ea_style` option holding hex values for the accent
 * colour the widgets fall back to, which is a style preference: wiring it as
 * module state would let an agent "disable" a colour.
 *
 * Everything else — the actions, the capability checks, the refusals — comes from
 * the shared engine.
 */

// Guarded at the require site: a Skill add-on may ship its own copy of the
// engine at a different path, and require_once cannot see a duplicate it was
// never given. Two copies means two declarations and a fatal on compile.
if (!function_exists('nibwp_elpack_register')) {
    require_once __DIR__ . '/elementor-pack-engine.php';
}

/**
 * The element names the pack gates its own widget registrations on.
 *
 * Transcribed from the pack rather than asked of it: the list lives in the field
 * definitions of a settings class that is instantiated without keeping a
 * reference, and its only accessor is an instance method whose constructor adds
 * the admin_menu and admin_init hooks again — reading the list that way would
 * print the pack's settings menu twice. These are the bare names; the option key
 * is the pack's own prefix in front of each, which is how it builds them too.
 *
 * @return array<int, string>
 */
function nibwp_wpb_addons_elements(): array
{
    return [
        'content_box',
        'counter',
        'fancy_list',
        'image_gallery',
        'logo_slider',
        'news_ticker',
        'post_grid_slider',
        'pricing_tables',
        'service_box',
        'slider',
        'team_members',
        'testimonials',
        'timeline',
        'video_popup',
        'videos_grid',
    ];
}

/**
 * Which of the pack's elements are switched on.
 *
 * @return array<string, bool>|WP_Error
 */
function nibwp_wpb_addons_module_state(): array|WP_Error
{
    // The prefix constant is defined by the main plugin file itself, above every
    // Elementor check, so it holds wherever the option keys below mean anything —
    // and its absence is the site that does not have this pack, which gets a
    // refusal rather than a list of fifteen switches it does not own.
    if (!defined('WPB_EA_PREFIX')) {
        return new WP_Error(
            'nibwp_wpb_addons_inactive',
            __('This add-on is not loaded, so it has no element switches to report.', 'nibwp'),
            ['status' => 400]
        );
    }

    $prefix = (string) WPB_EA_PREFIX;
    $saved = get_option('wpb_ea_addons', []);
    $saved = is_array($saved) ? $saved : [];
    $state = [];

    // ABSENT MEANS ON, and 'off' is a string. Both readings are the pack's own: it
    // passes 'on' as the default for every element and treats anything that is not
    // exactly 'on' as off.
    foreach (nibwp_wpb_addons_elements() as $element) {
        $key = $prefix . $element;
        $state[$key] = !array_key_exists($key, $saved) || $saved[$key] === 'on';
    }

    // The pack's Elements fields run through a filter of its own, so a key in the
    // option that this build does not ship is a toggle something else added to the
    // same tab. Reporting it is what makes it possible to switch one back on from
    // here; it is read the same way, against 'on', because that is the only value
    // the pack itself accepts.
    foreach ($saved as $key => $value) {
        $key = (string) $key;

        if (!array_key_exists($key, $state)) {
            $state[$key] = $value === 'on';
        }
    }

    return $state;
}

/**
 * Switch one element on or off, in the pack's own storage format.
 */
function nibwp_wpb_addons_set_module(string $slug, bool $on): bool|WP_Error
{
    $state = nibwp_wpb_addons_module_state();

    if ($state instanceof WP_Error) {
        return $state;
    }

    if (!array_key_exists($slug, $state)) {
        return new WP_Error(
            'nibwp_wpb_addons_unknown_module',
            __('That element is not one this add-on offers.', 'nibwp'),
            ['status' => 400]
        );
    }

    $saved = get_option('wpb_ea_addons', []);

    // The option is created empty on the pack's first admin request, so start from
    // an array whatever is there and keep every key already in it.
    $saved = is_array($saved) ? $saved : [];

    // Written as the settings form writes it: the literal string, not a bool and
    // not an unset key, so the pack's own screen shows the same state afterwards.
    $saved[$slug] = $on ? 'on' : 'off';

    update_option('wpb_ea_addons', $saved);

    return true;
}

nibwp_elpack_register([
    'key' => 'wpb-addons',
    'name' => 'WPB Elementor Addons',
    'dir' => 'wpb-elementor-addons',
    // Defined by the main plugin file's own constructor, which runs as that file
    // loads and before the Elementor check, so they hold even on a request where
    // the pack goes on to include nothing and register nothing.
    'constants' => ['WPB_EA_VERSION', 'WPB_EA_PATH', 'WPB_EA_PREFIX'],
    'classes' => ['WPB_Elementor_Addons'],
    // Its wpb_ea_* helper functions are deliberately not markers: they live in
    // files the pack includes only once Elementor has loaded, so they are absent
    // on exactly the install this integration most needs to describe.
    'description' => 'Manage this widget pack on this site: list the fifteen widgets it contributes to the Elementor editor, read one widget\'s full control schema so a layout '
        . 'can be built with it, find which pages already use it, and switch any of its elements on or off — the pack gates every widget registration on that switch, so one '
        . 'that is off is not in the editor at all. It keeps no templates of its own, so there is nothing else here to manage.',
    'modules' => [
        'label' => 'element',
        'read' => 'nibwp_wpb_addons_module_state',
        'write' => 'nibwp_wpb_addons_set_module',
    ],
    'notes' => [
        "THE SWITCHES ARE THE ELEMENTS TAB of the pack's own settings screen, stored\n"
        . "in the wpb_ea_addons option as 'on' or 'off' per element. Switching one off\n"
        . "stops the widget being registered at all, so it leaves the editor completely\n"
        . "rather than merely being hidden, and a page that already uses it keeps the\n"
        . "widget in its saved data and renders nothing where it was. Run find_usage\n"
        . "before disable_module, and re-enable rather than rebuild.",

        "THE SWITCH SLUG IS NOT THE WIDGET NAME, and several pairs do not look\n"
        . "related. Pass the wpb_ea_ slug to enable_module and disable_module, and the\n"
        . "widget name to describe_widget and find_usage:\n"
        . "  wpb_ea_content_box        wpb-our-content-box\n"
        . "  wpb_ea_counter            wpb-ea-counterup-settings\n"
        . "  wpb_ea_fancy_list         wpb-ea-fany-list\n"
        . "  wpb_ea_image_gallery      wpb-ea-image-gallery\n"
        . "  wpb_ea_logo_slider        wpb-ea-logo-slider\n"
        . "  wpb_ea_news_ticker        wpb-ea-news-ticker\n"
        . "  wpb_ea_post_grid_slider   wpb-ea-post-grid-slider\n"
        . "  wpb_ea_pricing_tables     wpb-ea-pricing-table\n"
        . "  wpb_ea_service_box        wpb-service-box\n"
        . "  wpb_ea_slider             wpb-ea-slider-item\n"
        . "  wpb_ea_team_members       wpb-ea-team-members\n"
        . "  wpb_ea_testimonials       wpb-ea-testimonial\n"
        . "  wpb_ea_timeline           wpb-ea-timeline\n"
        . "  wpb_ea_video_popup        wpb-ea-video-popup\n"
        . "  wpb_ea_videos_grid        wpb-ea-videos-grid\n"
        . "Titles read 'WPB <thing>' throughout, except the news ticker, which is just\n"
        . "'News Ticker', and the filterable image gallery, which is 'WPB Gallery'.\n"
        . "Every one reports the single category wpb_ea_widgets, shown in the editor as\n"
        . "WPB ADDONS, so the category tells you nothing about a widget — group these by\n"
        . "name.",

        "NO TEMPLATE POST TYPE, so no template actions, and that is not a version gap:\n"
        . "the pack calls register_post_type nowhere. It carries one helper that lists\n"
        . "Elementor's own elementor_library posts, and nothing in the pack calls it.\n"
        . "Saved layouts on such a site are Elementor's, so work with them through the\n"
        . "Elementor abilities.",

        "THE STYLE OPTION IS NOT A SWITCH LIST. wpb_ea_style holds three hex colours —\n"
        . "wpb_ea_primary_color, wpb_ea_primary_color_light and\n"
        . "wpb_ea_primary_color_dark, defaulting to #3878ff, #7ca6ff and #004dcb — used\n"
        . "as the accent the widgets fall back to when a control leaves it unset, and for\n"
        . "one inline stylesheet on the front end. They are a colour preference, not\n"
        . "module state, and nothing here reads or writes them.",

        "AN EMPTY WIDGET LIST IS USUALLY THE REQUEST. Registration happens on\n"
        . "Elementor's own widget hook, so a front-end call sees nothing while the pack\n"
        . "is perfectly active; read zero widgets as a finding only from an editor\n"
        . "context. There is one other way to get a short list with everything switched\n"
        . "on: the pack decides at load time whether Elementor is already loaded, and if\n"
        . "it is not, it includes none of its own files and registers nothing for the\n"
        . "rest of that request — while still reporting as active, because its constants\n"
        . "are defined either way. list_modules works in that state; the widget count\n"
        . "does not.",
    ],
]);
