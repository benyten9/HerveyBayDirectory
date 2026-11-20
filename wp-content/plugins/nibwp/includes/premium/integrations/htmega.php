<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: the HT Mega element pack and its theme builder.
 *
 * Templates are `htmega_theme_builder` posts — the post type is registered from
 * a class constant, which is why a search for a literal name finds nothing, and
 * it is registered on every request the pack boots on: the theme builder file is
 * required unconditionally and instantiates itself at the bottom
 * (includes/class.htmega.php:114 → admin/include/class.theme-builder.php:755).
 * `_htmega_template_type` is the kind and it names one of seven slots.
 *
 * WHERE a template is output is not post meta. The pack keeps one chosen
 * template per slot in a single option, `htmega_themebuilder_module_settings`,
 * as a JSON string under the `themebuilder` key: {slot: template id} plus the
 * module's own enable flag. So there is no condition meta key to declare, and
 * the reader and writer below work on that option instead — assigning a
 * template is the only "where does this appear" decision the pack has.
 *
 * The widget on/off state is four options of the same shape, each a flat
 * slug => 'on'|'off' array, and a slug the option has never carried falls back
 * to the default on the pack's own settings field. So the read rebuilds the
 * state from those two sources directly rather than from the pack's widget
 * registrar, whose own list is private and whose answer also depends on which
 * widget files happen to exist in the installed build.
 *
 * Everything else — the actions, the capability checks, the refusal to touch a
 * post that is not one of these — comes from the shared engine.
 */

// Guarded at the require site: a Skill add-on may ship its own copy of the
// engine at a different path, and require_once cannot see a duplicate it was
// never given. Two copies means two declarations and a fatal on compile.
if (!function_exists('nibwp_elpack_register')) {
    require_once __DIR__ . '/elementor-pack-engine.php';
}

/**
 * The options holding the pack's Elementor widget on/off lists.
 *
 * Four separate screens, one storage shape: the main widget list, the widgets
 * that need a third-party plugin, the 2026 section collection, and the theme
 * builder's own widgets. Every one is a flat option of slug => 'on'|'off' read
 * with the same `=== 'on'` test, so one loop covers them all.
 *
 * @return array<int, string>
 */
function nibwp_htmega_widget_options(): array
{
    return [
        'htmega_element_tabs',
        'htmega_thirdparty_element_tabs',
        'htmega_sections_element_tabs',
        'htmega_themebuilder_element_tabs',
    ];
}

/**
 * Every widget slug the pack can switch, with the option it lives in and the
 * state it has when that option has never carried it.
 *
 * Taken from the pack's own settings field list, which is what the pack itself
 * consults for the same purpose (admin/include/settings-panel/includes/classes/
 * Onboarding.php:84-88), and read fresh on every call: it is the default list,
 * never the saved state, so it cannot go stale against a write.
 *
 * @return array<string, array{option: string, default: bool}>|WP_Error
 */
function nibwp_htmega_widget_fields(): array|WP_Error
{
    $registered = null;

    if (
        class_exists('\HTMegaOpt\Admin\Options_Field')
        && method_exists('\HTMegaOpt\Admin\Options_Field', 'get_registered_settings')
    ) {
        // The field list is assembled on the spot and pulls in the pack's own
        // template dropdowns, so a version that changed either could throw
        // where the previous one returned. Not worth failing a read over.
        try {
            $registered = \HTMegaOpt\Admin\Options_Field::instance()->get_registered_settings();
        } catch (\Throwable $error) {
            $registered = null;
        }
    }

    if (!is_array($registered)) {
        return new WP_Error(
            'nibwp_htmega_no_widget_list',
            __('This version of the add-on does not publish the list of widgets that can be switched off.', 'nibwp'),
            ['status' => 400]
        );
    }

    $fields = [];

    foreach (nibwp_htmega_widget_options() as $option) {
        foreach ((array) ($registered[$option] ?? []) as $field) {
            $field = (array) $field;

            // The same screens also hold text, colour and select controls and a
            // promotional block; only a field of type 'element' is a widget
            // toggle, which is the test the pack's own registrar makes.
            if ((string) ($field['type'] ?? '') !== 'element') {
                continue;
            }

            $slug = (string) ($field['id'] ?? '');

            if ($slug === '' || isset($fields[$slug])) {
                continue;
            }

            // A field the pack marks as premium is skipped by the pack's own
            // save handler (Api/Settings.php:174), so this option never carries
            // it, and in a build without that widget's file nothing would
            // register even if it did (includes/widgets_control.php:216).
            // Switching one from here would report a change the editor does not
            // make. When the premium build stops flagging them they appear here
            // on their own, because this list comes back through the pack's own
            // htmega_admin_fields filter.
            if (!empty($field['is_pro'])) {
                continue;
            }

            $fields[$slug] = [
                'option' => $option,
                'default' => (string) ($field['default'] ?? 'on') === 'on',
            ];
        }
    }

    // A slug the owner has already switched that the field list no longer
    // offers — one a premium build contributed, or one this version dropped.
    // Reporting it is what makes it possible to switch back on from here.
    foreach (nibwp_htmega_widget_options() as $option) {
        $saved = get_option($option, []);

        if (!is_array($saved)) {
            continue;
        }

        foreach ($saved as $slug => $value) {
            $slug = (string) $slug;

            if ($slug === '' || isset($fields[$slug]) || !is_string($value)) {
                continue;
            }

            $fields[$slug] = ['option' => $option, 'default' => $value === 'on'];
        }
    }

    if ($fields === []) {
        return new WP_Error(
            'nibwp_htmega_no_widget_list',
            __('The add-on published no widget list this integration could read.', 'nibwp'),
            ['status' => 400]
        );
    }

    return $fields;
}

/**
 * The widget slugs this pack offers and whether each is switched on.
 *
 * @return array<string, bool>|WP_Error
 */
function nibwp_htmega_widget_state(): array|WP_Error
{
    $fields = nibwp_htmega_widget_fields();

    if ($fields instanceof WP_Error) {
        return $fields;
    }

    $saved = [];

    foreach (nibwp_htmega_widget_options() as $option) {
        $stored = get_option($option, []);
        $saved[$option] = is_array($stored) ? $stored : [];
    }

    $state = [];

    foreach ($fields as $slug => $field) {
        $stored = $saved[$field['option']] ?? [];

        // The pack's test, exactly: 'on' is on and anything else is off, and a
        // slug the option has never carried takes the field's own default —
        // which is 'off' for nineteen of the main list's widgets, so treating
        // absence as enabled would report them the wrong way round.
        $state[$slug] = array_key_exists($slug, $stored)
            ? (string) $stored[$slug] === 'on'
            : $field['default'];
    }

    return $state;
}

/**
 * Switch one widget on or off, in the pack's own storage format.
 */
function nibwp_htmega_set_widget(string $slug, bool $on): bool|WP_Error
{
    $fields = nibwp_htmega_widget_fields();

    if ($fields instanceof WP_Error) {
        return $fields;
    }

    if (!isset($fields[$slug])) {
        return new WP_Error(
            'nibwp_htmega_unknown_widget',
            __('That widget is not one this add-on offers.', 'nibwp'),
            ['status' => 400]
        );
    }

    $option = (string) $fields[$slug]['option'];
    $saved = get_option($option, []);
    $saved = is_array($saved) ? $saved : [];

    // Only this slug's key changes; every other widget's state in the same
    // option is left exactly as the owner set it.
    $saved[$slug] = $on ? 'on' : 'off';

    update_option($option, $saved);

    return true;
}

/**
 * Which template the site outputs for each slot, as the pack stores it.
 *
 * One option, one JSON string, keyed by slot name, holding a template id — and
 * the Theme Builder module's enable flag alongside them in the same map.
 *
 * @return array<string, string>
 */
function nibwp_htmega_assignments(): array
{
    $settings = get_option('htmega_themebuilder_module_settings', []);

    if (!is_array($settings) || !isset($settings['themebuilder'])) {
        return [];
    }

    $decoded = json_decode((string) $settings['themebuilder'], true);

    if (!is_array($decoded)) {
        return [];
    }

    $map = [];

    foreach ($decoded as $key => $value) {
        if (is_string($key) && (is_string($value) || is_int($value))) {
            $map[$key] = (string) $value;
        }
    }

    return $map;
}

/**
 * Is this template the one its slot is set to?
 *
 * @param array<string, mixed> $group
 * @return array<string, bool>
 */
function nibwp_htmega_read_assignment(WP_Post $post, array $group): array
{
    $kind = (string) get_post_meta($post->ID, '_htmega_template_type', true);

    if ($kind === '') {
        return ['assigned' => false];
    }

    $chosen = nibwp_htmega_assignments()[$kind] ?? '0';

    // The pack treats an id of '0' as nothing chosen and falls back to the
    // option its older releases used, so the same fallback is made here —
    // otherwise a site still on the old storage reads as having no template
    // assigned while its header renders perfectly well.
    if ($chosen === '' || $chosen === '0') {
        $legacy = get_option('htmegabuilder_templatebuilder_tabs', []);
        $chosen = is_array($legacy) && isset($legacy[$kind]) ? (string) $legacy[$kind] : '0';
    }

    return ['assigned' => $chosen !== '0' && (int) $chosen === $post->ID];
}

/**
 * Assign this template to its slot, or clear the slot.
 *
 * @param mixed                $value
 * @param array<string, mixed> $group
 */
function nibwp_htmega_write_assignment(WP_Post $post, string $label, $value, array $group): bool|WP_Error
{
    $kind = (string) get_post_meta($post->ID, '_htmega_template_type', true);

    if ($kind === '') {
        return new WP_Error(
            'nibwp_htmega_kind_missing',
            __('Set the template kind first: this add-on assigns one template per kind, so a template with no kind has no slot to be assigned to.', 'nibwp'),
            ['status' => 400]
        );
    }

    $on = filter_var($value, FILTER_VALIDATE_BOOLEAN);
    $settings = get_option('htmega_themebuilder_module_settings', []);
    $settings = is_array($settings) ? $settings : [];

    $map = nibwp_htmega_assignments();

    // One template per slot: assigning this one replaces whatever the slot held,
    // and the id is written as a string because that is what the pack's own
    // handler stores and what its comparisons expect.
    $map[$kind] = $on ? (string) $post->ID : '0';

    // An assignment the module never reads changes nothing, so the pack's own
    // toggle switches the Theme Builder module on at the same time. It does
    // that when clearing a slot too; here only assigning does, so clearing one
    // cannot switch on a module the owner had off.
    if ($on) {
        $map['themebuilder_enable'] = 'on';
    }

    $encoded = wp_json_encode($map);

    if (!is_string($encoded)) {
        return new WP_Error(
            'nibwp_htmega_assignment_failed',
            __('This add-on\'s template assignments could not be written.', 'nibwp'),
            ['status' => 500]
        );
    }

    $settings['themebuilder'] = $encoded;

    update_option('htmega_themebuilder_module_settings', $settings);

    return true;
}

/**
 * Keep Elementor's own template-type meta in step with the pack's kind.
 *
 * Elementor decides which document class opens the editor from
 * `_elementor_template_type`, and the pack stamps it with the same value as its
 * own kind meta every time it creates a template
 * (admin/include/class.theme-builder.php:320-323 and 441-444). The value IS the
 * kind, which a fixed create_meta map cannot express, so it is mirrored once the
 * kind has been written.
 *
 * @param array<string, mixed> $pack
 */
function nibwp_htmega_mirror_template_type(int $post_id, array $pack): void
{
    $kind = (string) get_post_meta($post_id, '_htmega_template_type', true);

    if ($kind === '') {
        return;
    }

    update_post_meta($post_id, '_elementor_template_type', $kind);
}

nibwp_elpack_register([
    'key' => 'htmega',
    'name' => 'HT Mega Addons for Elementor',
    'dir' => 'ht-mega-for-elementor',
    'constants' => ['HTMEGA_VERSION', 'HTMEGA_ADDONS_PL_ROOT'],
    'classes' => ['HTMega_Addons_Elementor', 'HTMega_Widgets_Control'],
    'description' => 'Manage this element pack on this site: list the widgets it contributes to the editor and switch any of them on or off, and work with its theme '
        . 'builder templates — header, footer, single and blog layouts — including which template each slot is set to. Also reports templates that were never opened in '
        . 'Elementor, and published templates no slot is set to, so they appear nowhere.',
    'templates' => [
        [
            // The pack's only post type. It registers it from a class constant,
            // so a search for the name as a literal finds nothing.
            'post_type' => 'htmega_theme_builder',
            'kind_meta' => '_htmega_template_type',
            'kinds' => [
                'header_page',
                'footer_page',
                'single_blog_page',
                'archive_blog_page',
                'search_page',
                'error_page',
                'coming_soon_page',
            ],
            // What the pack's own Add New stamps beside the builder flag. The
            // page template is not cosmetic here: the theme builder picks which
            // wrapper file it loads from the template post's page template slug
            // (extensions/ht-builder/classes/class.template_builder.php:93-104),
            // so a template created without it renders inside the theme's own
            // single-post layout instead of on its own.
            'create_meta' => [
                '_wp_page_template' => 'elementor_canvas',
                '_elementor_data' => '[]',
            ],
            // No condition meta key, because there is none: which template a
            // slot uses lives in a site option. 'assigned' is that decision,
            // read and written below.
            'conditions_labels' => ['assigned'],
            'conditions_read' => 'nibwp_htmega_read_assignment',
            'conditions_write' => 'nibwp_htmega_write_assignment',
        ],
    ],
    'after_template_write' => 'nibwp_htmega_mirror_template_type',
    'modules' => [
        'label' => 'widget',
        'read' => 'nibwp_htmega_widget_state',
        'write' => 'nibwp_htmega_set_widget',
    ],
    'notes' => [
        "TEMPLATE KINDS: header_page, footer_page, single_blog_page and\n"
        . "archive_blog_page are the four slots this build outputs. search_page,\n"
        . "error_page and coming_soon_page are kinds the pack understands and its\n"
        . "own tabs lock, so a template of those kinds is created and stored\n"
        . "correctly and nothing outputs it without the premium build. A template\n"
        . "with no kind at all has no slot and is never output.",

        "ONE TEMPLATE PER SLOT, AND IT IS NOT POST META. Which template a slot\n"
        . "uses is kept in the htmega_themebuilder_module_settings option, as a\n"
        . "JSON string of {kind: template id}. list_templates reports it as\n"
        . "conditions.assigned; pass assigned: true to update_template to point the\n"
        . "slot at that template, which replaces whatever the slot held, or\n"
        . "assigned: false to clear it. Assigning also switches the Theme Builder\n"
        . "module on, as the add-on's own toggle does, because nothing reads the\n"
        . "assignment while that module is off. Clearing a slot does not switch it\n"
        . "off again. A site still on the pack's older storage is read correctly\n"
        . "but writing moves it to the current option, which then wins.",

        "AUDIT: a published template no slot is set to is reported as having no\n"
        . "display conditions, which is exactly what it means here — it is built,\n"
        . "it is published, and nothing outputs it. On a site keeping alternates\n"
        . "for one slot, expect one finding per alternate.",

        "WIDGET LISTS ARE FOUR OPTIONS OF ONE SHAPE, reported here as a single\n"
        . "list: the main widget list, the widgets that integrate another plugin,\n"
        . "the 2026 section collection, and the theme builder's own widgets. Three\n"
        . "things do not follow from a widget reading as on. One in the\n"
        . "integration list also needs its own plugin active before it registers.\n"
        . "One of the theme builder's own only reaches the editor while the Theme\n"
        . "Builder module is on. And a widget the premium build supplies is\n"
        . "declared inside that plugin's own directory, so list_widgets does not\n"
        . "report it and this list leaves out anything the pack still flags as\n"
        . "premium.",

        "WIDGET NAMES ARE NOT MODULE SLUGS. list_widgets and find_usage report\n"
        . "Elementor's widget names, which are the pack's own long form —\n"
        . "htmega-accordion-addons, htmega-pricing-table-addons — while\n"
        . "enable_module and disable_module take the settings slug, accordion or\n"
        . "pricingtable. The pack maps the two itself in\n"
        . "htmega_template_import_widget_type_settings_map(), in\n"
        . "includes/template-import-widget-enable.php. Run list_modules and match\n"
        . "on the recognisable part rather than guessing the slug from the name.",

        "THE MODULES SCREEN IS SEPARATE STORAGE and is not in this list. Its\n"
        . "feature toggles — theme builder, mega menu, post duplicator, sale\n"
        . "notification, wrapper link, floating effects, reading bar, scroll to\n"
        . "top — live in htmega_advance_element_tabs, but for the four that were\n"
        . "given a screen of their own the pack now reads a per-module option\n"
        . "first and only falls back to that tab while the module option is empty\n"
        . "(includes/class.htmega.php:189-190, 208-209). A write to the old tab on\n"
        . "a site that has saved the new one is stored and ignored, so those\n"
        . "features are switched from the add-on's own Modules screen.",

        "MEGA MENUS ARE NOT TEMPLATES: the pack keeps a mega menu as meta on the\n"
        . "nav menu item itself, pointing at an Elementor template, so there is no\n"
        . "post type for them and nothing here lists or creates one. Its Gutenberg\n"
        . "blocks are switched by two more options of the same shape and are left\n"
        . "out of this list, which is the Elementor widgets only.",
    ],
]);
