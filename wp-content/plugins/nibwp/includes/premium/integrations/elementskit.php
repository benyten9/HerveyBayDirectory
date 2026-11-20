<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: the ElementsKit add-on pack.
 *
 * Two of its three post types are an owner's to manage. `elementskit_template`
 * holds the header and footer templates the theme hooks output, with
 * `elementskit_template_type` naming the slot and four meta keys carrying the
 * display rules — one of which, `elementskit_template_activation`, is the
 * switch the front end filters on before it looks at any of the others.
 * `elementskit_widget` holds Widget Builder widgets. The third,
 * `elementskit_content`, is left out: the pack creates and names those posts
 * itself to back a widget's inline content editor, so there is nothing there
 * for anyone to manage.
 *
 * Module state lives in the `elementskit_options` option under `module_list`,
 * as slug => the module's own config row with a `status` of active or inactive.
 * A slug with no row is ON, so the read below starts from the pack's default
 * list and only overrides what the option actually says.
 *
 * That default list comes from a fresh Module_List rather than the singleton:
 * the singleton resolves its active list once in its constructor and memoises
 * the instance, so a read taken through it straight after a write would report
 * the state from before.
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
 * The modules this build of the pack actually ships, with their config rows.
 *
 * @return array<string, array<string, mixed>>|WP_Error Slug => required flag and config row.
 */
function nibwp_elementskit_defaults(string $kind): array|WP_Error
{
    // Both lists are Config_List subclasses with the same shape, so one reader
    // serves both. A fresh instance rather than ::instance(): the singleton trait
    // memoises, and Config_List resolves its active list once in the
    // constructor, so a read after a write through it returns the old value.
    $class = $kind === 'widget'
        ? 'ElementsKit_Lite\\Config\\Widget_List'
        : 'ElementsKit_Lite\\Config\\Module_List';

    if (!class_exists($class)) {
        return new WP_Error(
            'nibwp_elementskit_no_' . $kind . '_list',
            $kind === 'widget'
                ? __('This version of the add-on does not expose a widget list.', 'nibwp')
                : __('This version of the add-on does not expose a module list.', 'nibwp'),
            ['status' => 400]
        );
    }

    // The constructor resolves its active list immediately, and that path reaches
    // for the pack's framework classes. On a site where the pack loaded properly
    // they are there; with a half-finished update, or another plugin's autoloader
    // in the way, it throws — and a throw here would take down whatever request
    // asked rather than refusing one action.
    try {
        $config = new $class();
    } catch (\Throwable $error) {
        return new WP_Error(
            'nibwp_elementskit_list_unavailable',
            __('The add-on could not report its list on this request.', 'nibwp'),
            ['status' => 500]
        );
    }

    $defaults = [];

    // Required modules first, and flagged: the pack's own activation check
    // exempts them, so an inactive status written against one is ignored.
    foreach ((array) $config->get_list('required') as $slug => $module) {
        $defaults[(string) $slug] = ['required' => true, 'config' => (array) $module];
    }

    foreach ((array) $config->get_list('optional') as $slug => $module) {
        $module = (array) $module;


        // A 'pro-disabled' row is an upsell line on the pack's settings screen,
        // not a module this build can run. Reporting it as a module that is off
        // would invite a switch that writes a status and changes nothing.
        if ((string) ($module['package'] ?? '') === 'pro-disabled') {
            continue;
        }

        $defaults[(string) $slug] = ['required' => false, 'config' => $module];
    }

    return $defaults;
}

/**
 * The `module_list` the option holds right now.
 *
 * @return array<string, mixed>
 */
function nibwp_elementskit_saved(string $kind): array
{
    $options = get_option('elementskit_options', []);
    $key = $kind . '_list';

    if (!is_array($options) || !isset($options[$key]) || !is_array($options[$key])) {
        return [];
    }

    return $options[$key];
}

/**
 * The module slugs this pack offers and whether each is switched on.
 *
 * @return array<string, bool>|WP_Error
 */
function nibwp_elementskit_state(string $kind): array|WP_Error
{
    $defaults = nibwp_elementskit_defaults($kind);

    if ($defaults instanceof WP_Error) {
        return $defaults;
    }

    $saved = nibwp_elementskit_saved($kind);
    $state = [];

    foreach ($defaults as $slug => $module) {
        if ($module['required'] === true) {
            $state[$slug] = true;
            continue;
        }

        // Only an explicit 'inactive' switches a module off. The pack rewrites
        // the whole list whenever its settings are saved, but a module a later
        // version adds — or a theme adds through the list filter — has no row
        // yet and runs, so absent must not read as off.
        $state[$slug] = (string) ($saved[$slug]['status'] ?? '') !== 'inactive';
    }

    return $state;
}

/**
 * Switch one module on or off, in the pack's own storage format.
 */
function nibwp_elementskit_set(string $kind, string $slug, bool $on): bool|WP_Error
{
    $defaults = nibwp_elementskit_defaults($kind);

    if ($defaults instanceof WP_Error) {
        return $defaults;
    }

    if (!array_key_exists($slug, $defaults)) {
        return new WP_Error(
            'nibwp_elementskit_unknown_' . $kind,
            $kind === 'widget'
                ? __('That widget is not one this add-on offers.', 'nibwp')
                : __('That module is not one this add-on offers.', 'nibwp'),
            ['status' => 400]
        );
    }

    // Saying so beats writing a status the pack reads and ignores, which would
    // look like a change that took effect.
    if ($defaults[$slug]['required'] === true) {
        return new WP_Error(
            'nibwp_elementskit_module_required',
            __('The rest of the add-on depends on that module, so it stays on whatever is stored for it.', 'nibwp'),
            ['status' => 400]
        );
    }

    $options = get_option('elementskit_options', []);
    $options = is_array($options) ? $options : [];
    $key = $kind . '_list';
    $list = isset($options[$key]) && is_array($options[$key]) ? $options[$key] : [];

    // The pack stores each module's whole config row and hangs `status` off it,
    // so a slug with no row yet is seeded from the default rather than written
    // as a bare status its settings screen has no title or package for.
    $row = isset($list[$slug]) && is_array($list[$slug]) ? $list[$slug] : $defaults[$slug]['config'];
    $row['status'] = $on ? 'active' : 'inactive';

    $list[$slug] = $row;
    $options[$key] = $list;

    // One option carries the pack's settings, its widget list and its licence
    // data as well, so it is read, changed and written back whole.
    update_option('elementskit_options', $options);

    return true;
}

/** @return array<string, bool>|WP_Error */
function nibwp_elementskit_module_state(): array|WP_Error
{
    return nibwp_elementskit_state('module');
}

/** @return array<string, bool>|WP_Error */
function nibwp_elementskit_widget_state(): array|WP_Error
{
    return nibwp_elementskit_state('widget');
}

function nibwp_elementskit_set_module(string $slug, bool $on): bool|WP_Error
{
    return nibwp_elementskit_set('module', $slug, $on);
}

function nibwp_elementskit_set_widget(string $slug, bool $on): bool|WP_Error
{
    return nibwp_elementskit_set('widget', $slug, $on);
}

nibwp_elpack_register([
    'key' => 'elementskit',
    'name' => 'ElementsKit',
    'dir' => 'elementskit-lite',
    'classes' => ['ElementsKit_Lite', 'ElementsKit_Lite\Plugin'],
    'description' => 'Manage the templates and custom widgets this add-on stores: list the header and footer templates, create one, switch it on and set which pages it covers, '
        . 'and turn the add-on\'s own feature modules on or off. Also reports templates that were never opened in Elementor and ones published with nothing to show them on.',
    'templates' => [
        [
            'post_type' => 'elementskit_template',
            'kind_meta' => 'elementskit_template_type',
            'kinds' => ['header', 'footer', 'section'],
            'conditions' => [
                'activation' => 'elementskit_template_activation',
                'condition_a' => 'elementskit_template_condition_a',
                'condition_singular' => 'elementskit_template_condition_singular',
                'condition_singular_id' => 'elementskit_template_condition_singular_id',
            ],
        ],
        [
            // Widget Builder widgets. No kind and no display rules: where one
            // shows is decided by the page that drops it in, like any widget.
            'post_type' => 'elementskit_widget',
        ],
    ],
    // Two lists, not one: the pack keeps its feature modules and its individual
    // widgets in two keys of the same option, and switching a widget off is what
    // removes it from the editor. With a single read/write pair the widget list
    // was unreachable.
    'modules' => [
        'module' => [
            'label' => 'module',
            'read' => 'nibwp_elementskit_module_state',
            'write' => 'nibwp_elementskit_set_module',
        ],
        'widget' => [
            'label' => 'widget',
            'read' => 'nibwp_elementskit_widget_state',
            'write' => 'nibwp_elementskit_set_widget',
        ],
    ],
    'notes' => [
        "TEMPLATE POST TYPES: elementskit_template holds the header and footer\n"
        . "templates the theme hooks output. elementskit_widget holds Widget Builder\n"
        . "widgets, and one of those is not its Elementor layout — it is the\n"
        . "elementskit_custom_widget_data meta plus a PHP file the pack compiles from\n"
        . "it. create_template on that type leaves a shell that never shows up in the\n"
        . "editor, so list, read and delete those and build new ones in the pack's own\n"
        . "builder. It is also what create_template falls back to when the header and\n"
        . "footer module is off and its post type is therefore unregistered, so name\n"
        . "post_type on every create_template rather than taking the default.",

        "ACTIVATION IS THE REAL SWITCH: the front end only considers templates whose\n"
        . "activation is the exact string 'yes'. A template with a kind, a condition\n"
        . "and no activation is output nowhere. audit will not catch that either: it\n"
        . "treats any non-empty condition value as a condition, and an activation of\n"
        . "'no' is non-empty. Read activation yourself before calling a template live.",

        "CONDITION VALUES: condition_a is entire_site, singular or archive, and this\n"
        . "build's front end only ever matches entire_site — the other two are stored\n"
        . "and never met. condition_singular is all, front_page, all_posts, all_pages,\n"
        . "selective or 404page. condition_singular_id is a comma-separated STRING of\n"
        . "post ids, not an array: the pack implodes before saving and explodes on\n"
        . "read, so an array written there matches nothing.",

        "TEMPLATE KINDS: header and footer are the two the front end outputs, and it\n"
        . "keeps one of each per request — several active headers do not stack, the\n"
        . "pack keeps whichever the post id order leaves it holding. 'section' is a\n"
        . "value the pack's own save path understands, and blanks the conditions for,\n"
        . "but nothing in this build renders it.",

        "MODULES ARE FEATURES, NOT WIDGETS: header-footer owns elementskit_template\n"
        . "and widget-builder owns elementskit_widget, so switching one off stops its\n"
        . "post type being registered and list_templates goes quiet while the posts\n"
        . "sit there untouched. dynamic-content and layout-manager cannot be switched\n"
        . "off. The pack keeps a separate on/off list for its individual widgets under\n"
        . "the same option and nothing here writes it, so disable_module never takes a\n"
        . "single widget out of the editor.",
    ],
]);
