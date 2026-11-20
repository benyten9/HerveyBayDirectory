<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: the ElementInvader widget pack for Elementor.
 *
 * A minimal descriptor, and every omission is a finding rather than a gap.
 *
 * There is no template post type: the pack calls register_post_type nowhere at
 * all, not even from a variable. Where it needs a saved layout — the blog grid's
 * card and popup — the control takes a template id and its own description links
 * to Elementor's library, so those are elementor_library posts and belong to the
 * Elementor abilities.
 *
 * There is no module list either. The eighteen widgets are handed to Elementor
 * by a fixed run of register calls with no option, filter or setting in front of
 * them, so nothing can be switched off and a read/write pair would be standing
 * in front of nothing.
 *
 * The three option-ish names in this plugin are none of them a module list.
 * eli_db_version is a schema marker gating the newsletter table's own dbDelta.
 * eli_form_<widget id> is a per-widget copy of one form's mail configuration,
 * written only from inside the editor, whose only reader the pack itself
 * commented out as deprecated — and those copies carry secrets, so nothing here
 * goes near them. wdk_listing_page is not this plugin's option at all: it
 * belongs to a separate listings plugin and this pack only reads it.
 *
 * What is left is worth having on its own: what the pack contributes to the
 * editor, any widget's full control schema, and which pages would break if it
 * went away. Everything else — the actions, the capability checks, the refusals
 * — comes from the shared engine.
 */

// Guarded at the require site: a Skill add-on may ship its own copy of the
// engine at a different path, and require_once cannot see a duplicate it was
// never given. Two copies means two declarations and a fatal on compile.
if (!function_exists('nibwp_elpack_register')) {
    require_once __DIR__ . '/elementor-pack-engine.php';
}

nibwp_elpack_register([
    'key' => 'elementinvader',
    'name' => 'ElementInvader Addons for Elementor',
    'dir' => 'elementinvader-addons-for-elementor',
    // Defined at the very top of the main plugin file, above every Elementor
    // check, so they hold even on the startup path where Elementor is absent,
    // the pack returns early and none of its widget classes are ever loaded.
    'constants' => ['ELI_VERSION', 'ELI_PATH', 'ELI_FILE__'],
    // The form handler is included and instantiated at the main file's own
    // scope rather than behind a hook, so it is the class that is there
    // whenever the plugin is. The widget classes are not: they load inside the
    // widget-registration hook and exist on no other request.
    'classes' => ['ELI\Modules\Forms\Ajax_Handler'],
    'functions' => ['eli_notify_admin'],
    'description' => 'Take stock of this widget pack on this site: list the widgets it contributes to the editor, read one widget\'s full control schema so a layout can be built '
        . 'with it, and find which pages and templates already use it, which is the question that matters before removing or replacing the pack. This is inventory and usage '
        . 'rather than management — the pack registers no templates of its own and has nothing an owner can switch on or off, so there is nothing else here to manage.',
    'notes' => [
        "WIDGET NAMES are eli-<thing>: eli-contact-form, eli-newsletter, eli-menu,\n"
        . "eli-map, eli-slider, eli-logo, eli-pageloader, eli-currentdate,\n"
        . "eli-blog-search, eli-blog, eli-blog-post-counter and seven\n"
        . "eli-blog-preview-* parts. Titles all read 'Eli ...' and every widget\n"
        . "reports the single category 'elementinvader', so the category tells you\n"
        . "nothing about a widget — group these by name. Pass the eli- name to\n"
        . "describe_widget and find_usage, never the title. Note eli-blog is the blog\n"
        . "grid: its name and its title do not match.",

        "NO TEMPLATE POST TYPE, so no template actions, and this is not a version\n"
        . "gap — the pack registers no post type anywhere. The blog grid does take a\n"
        . "saved layout for its cards and its popup, but the control holds a template\n"
        . "id and links to Elementor's library to make one, so those layouts are\n"
        . "elementor_library posts: work with them through the Elementor abilities.",

        "NOTHING TO SWITCH OFF. The widgets are registered by a fixed list of calls\n"
        . "with no option, filter or settings screen gating any of them, so there is\n"
        . "no list_modules and no module state to report. A widget missing from\n"
        . "list_widgets therefore means its class did not load, not that an owner\n"
        . "disabled it — which is a real fault worth chasing rather than a preference.",

        "AN EMPTY LIST IS USUALLY THE REQUEST, NOT THE PACK. Every widget class is\n"
        . "loaded inside the widget-registration hook, so on a request where Elementor\n"
        . "never builds its registry this pack contributes nothing while being\n"
        . "perfectly active. Zero widgets from a front-end call is normal; read it as\n"
        . "a finding only from an editor context.",

        "ITS OPTIONS ARE NOT SETTINGS. eli_db_version only gates the schema work for\n"
        . "the pack's newsletter table. eli_form_<widget id> is a stale per-widget copy\n"
        . "of one form's mail configuration, written only while the editor is open, and\n"
        . "the pack commented out the code that read it; the copies also carry a\n"
        . "reCAPTCHA secret key, and a Mailchimp API key for the newsletter form.\n"
        . "wdk_listing_page belongs to a separate listings plugin and is only read. Do\n"
        . "not read a module list, a feature toggle or a site setting into any of the\n"
        . "three, and do not fetch them.",

        "FORM SUBMISSIONS ARE PERSONAL DATA and are outside this ability. Newsletter\n"
        . "signups are stored in a table of the pack's own with the whole submitted\n"
        . "payload alongside the address, and its Tools > Newsletter screen is where an\n"
        . "administrator reads or exports them. Nothing here returns them, and a\n"
        . "request to list or export subscribers belongs on that screen under the\n"
        . "site's own handling of personal data.",
    ],
]);
