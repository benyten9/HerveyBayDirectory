<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: the Happy Elementor Addons widget pack and its theme builder.
 *
 * Templates are `ha_library` posts. `_ha_library_type` says which slot a
 * template fills — header, footer, single or archive — and `_ha_display_cond`
 * holds the display rules as a flat array of the pack's own
 * "type/name/sub_name/sub_id" strings.
 *
 * The widget on/off list is stored the other way round from how it reads:
 * `happyaddons_inactive_widgets` lists the slugs that are switched OFF, and the
 * pack registers a widget precisely when its slug is ABSENT from that list. So
 * the read below inverts the option against the pack's own widget map to get
 * the slug => enabled shape the engine documents, and the write puts a slug in
 * the list to switch it off. Both go straight to the option rather than through
 * the pack, so a read after a write in the same request sees the write.
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
 * The widget slugs this pack can switch and whether each is switched on.
 *
 * @return array<string, bool>|WP_Error
 */
function nibwp_happy_addons_widget_state(): array|WP_Error
{
    if (
        !class_exists('Happy_Addons\Elementor\Classes\Widgets_Manager')
        || !method_exists('Happy_Addons\Elementor\Classes\Widgets_Manager', 'get_local_widgets_map')
    ) {
        return new WP_Error(
            'nibwp_happy_addons_no_widget_map',
            __('This version of the add-on does not expose a widget list.', 'nibwp'),
            ['status' => 400]
        );
    }

    $map = \Happy_Addons\Elementor\Classes\Widgets_Manager::get_local_widgets_map();

    // INVERTED: this option holds the slugs that are switched OFF, not the ones
    // that are on. A slug that is absent from it is enabled. Reading it as if it
    // listed what was on would report — and then write back — the exact opposite
    // of what the owner has.
    $off = get_option('happyaddons_inactive_widgets', []);
    $off = is_array($off) ? array_map('strval', $off) : [];

    $state = [];

    foreach (array_keys((array) $map) as $slug) {
        $state[(string) $slug] = !in_array((string) $slug, $off, true);
    }

    // A slug already in the option that the pack's own map does not have — a
    // paid add-on's widget, or one this version dropped — is still switched off.
    // Reporting it is what makes it possible to switch back on from here.
    foreach ($off as $slug) {
        $state[$slug] = false;
    }

    return $state;
}

/**
 * Switch one widget on or off, in the pack's own storage format.
 */
function nibwp_happy_addons_set_widget(string $slug, bool $on): bool|WP_Error
{
    $state = nibwp_happy_addons_widget_state();

    if ($state instanceof WP_Error) {
        return $state;
    }

    if (!array_key_exists($slug, $state)) {
        return new WP_Error(
            'nibwp_happy_addons_unknown_widget',
            __('That widget is not one this add-on offers.', 'nibwp'),
            ['status' => 400]
        );
    }

    $off = get_option('happyaddons_inactive_widgets', []);
    $off = is_array($off) ? array_map('strval', $off) : [];

    // Again the inversion: switching a widget ON means taking it OUT of the
    // list, switching it off means putting it in. The pack stores a plain list,
    // so the keys are renumbered rather than left with holes.
    $off = $on
        ? array_values(array_diff($off, [$slug]))
        : array_values(array_unique(array_merge($off, [$slug])));

    update_option('happyaddons_inactive_widgets', $off);

    return true;
}

/**
 * Ask the pack to rebuild the option its front end actually follows.
 *
 * Which template wins is read from a single generated option, not from each
 * template's own meta, and only the pack's editor rebuilds it. So a display rule
 * written from here was stored correctly while the site went on following the old
 * one — which reads as the write having done nothing at all.
 */
function nibwp_happy_addons_refresh_conditions(int $post_id, array $pack): void
{
    $cache = 'Happy_Addons\Elementor\Classes\Conditions_Cache';

    if (class_exists($cache) && method_exists($cache, 'regenerate')) {
        $cache::regenerate();
    }
}

/**
 * The universe of slugs for one of the pack's other two lists, and which of them
 * the pack keeps on no matter what is stored.
 *
 * @return array{map: array<string, mixed>, always_on: array<int, string>}|WP_Error
 */
function nibwp_happy_addons_catalogue(string $kind): array|WP_Error
{
    $manager = 'Happy_Addons\Elementor\Classes\Extensions_Manager';
    $getter = $kind === 'feature' ? 'get_local_features_map' : 'get_local_extensions_map';

    if (!class_exists($manager) || !method_exists($manager, $getter)) {
        return new WP_Error(
            'nibwp_happy_addons_no_' . $kind . '_map',
            $kind === 'feature'
                ? __('This version of the add-on does not expose a feature list.', 'nibwp')
                : __('This version of the add-on does not expose an extension list.', 'nibwp'),
            ['status' => 400]
        );
    }

    // The maps are plain literals, but they are reached through the pack's own
    // class: a half-loaded pack throws rather than returning nothing, and a throw
    // would take down the request instead of refusing one action.
    try {
        $map = (array) $manager::$getter();
        $always_on = $kind === 'feature' && method_exists($manager, 'get_always_on_features')
            ? array_map('strval', (array) $manager::get_always_on_features())
            : [];
    } catch (\Throwable $error) {
        return new WP_Error(
            'nibwp_happy_addons_' . $kind . '_map_failed',
            __('The add-on could not report that list on this request.', 'nibwp'),
            ['status' => 500]
        );
    }

    return ['map' => $map, 'always_on' => $always_on];
}

/**
 * Whether each slug in one of the other two lists is switched on.
 *
 * @return array<string, bool>|WP_Error
 */
function nibwp_happy_addons_state(string $kind): array|WP_Error
{
    $catalogue = nibwp_happy_addons_catalogue($kind);

    if ($catalogue instanceof WP_Error) {
        return $catalogue;
    }

    // INVERTED, like the widget list: the option holds what is switched OFF, so a
    // slug absent from it is enabled. Reading it the other way round would report
    // — and then write back — the opposite of what the owner has.
    $option = $kind === 'feature' ? 'happyaddons_inactive_features' : 'happyaddons_inactive_extensions';
    $off = get_option($option, []);
    $off = is_array($off) ? array_map('strval', $off) : [];

    $state = [];

    foreach (array_keys($catalogue['map']) as $slug) {
        $slug = (string) $slug;

        // An always-on feature is enabled by the pack whether or not it is in the
        // inactive list, so that is what is reported.
        $state[$slug] = in_array($slug, $catalogue['always_on'], true)
            || !in_array($slug, $off, true);
    }

    // A slug already in the option that this build's map does not have — a paid
    // add-on's, or one this version dropped — is still off, and reporting it is
    // what makes it switchable back on from here.
    foreach ($off as $slug) {
        if (!array_key_exists($slug, $state)) {
            $state[$slug] = false;
        }
    }

    return $state;
}

/**
 * Switch one slug in the other two lists, in the pack's own inverted format.
 */
function nibwp_happy_addons_set(string $kind, string $slug, bool $on): bool|WP_Error
{
    $state = nibwp_happy_addons_state($kind);

    if ($state instanceof WP_Error) {
        return $state;
    }

    $catalogue = nibwp_happy_addons_catalogue($kind);

    if ($catalogue instanceof WP_Error) {
        return $catalogue;
    }

    // Always-on is checked BEFORE the unknown check on purpose. The pack builds
    // its always-on list from its paid map as well as its free one, so such a slug
    // can be absent from this build's list entirely — and refusing it as "unknown"
    // would send the caller hunting for a typo instead of telling them the pack
    // keeps it on. Refusing at all beats writing a slug the pack then ignores,
    // which would read as a change that took effect and quietly did nothing.
    if (in_array($slug, $catalogue['always_on'], true)) {
        return new WP_Error(
            'nibwp_happy_addons_always_on',
            __('The add-on keeps that one on whatever is stored for it, so it cannot be switched off from here.', 'nibwp'),
            ['status' => 400]
        );
    }

    if (!array_key_exists($slug, $state)) {
        return new WP_Error(
            'nibwp_happy_addons_unknown_' . $kind,
            $kind === 'feature'
                ? __('That feature is not one this add-on offers.', 'nibwp')
                : __('That extension is not one this add-on offers.', 'nibwp'),
            ['status' => 400]
        );
    }

    $option = $kind === 'feature' ? 'happyaddons_inactive_features' : 'happyaddons_inactive_extensions';
    $off = get_option($option, []);
    $off = is_array($off) ? array_map('strval', $off) : [];

    // Again the inversion: on means taking the slug OUT of the list, off means
    // putting it in. The pack stores a plain list, so the keys are renumbered
    // rather than left with holes.
    $off = $on
        ? array_values(array_diff($off, [$slug]))
        : array_values(array_unique(array_merge($off, [$slug])));

    update_option($option, $off);

    return true;
}

/** @return array<string, bool>|WP_Error */
function nibwp_happy_addons_feature_state(): array|WP_Error
{
    return nibwp_happy_addons_state('feature');
}

/** @return array<string, bool>|WP_Error */
function nibwp_happy_addons_extension_state(): array|WP_Error
{
    return nibwp_happy_addons_state('extension');
}

function nibwp_happy_addons_set_feature(string $slug, bool $on): bool|WP_Error
{
    return nibwp_happy_addons_set('feature', $slug, $on);
}

function nibwp_happy_addons_set_extension(string $slug, bool $on): bool|WP_Error
{
    return nibwp_happy_addons_set('extension', $slug, $on);
}

nibwp_elpack_register([
    'key' => 'happy-addons',
    'name' => 'Happy Elementor Addons',
    'dir' => 'happy-elementor-addons',
    'constants' => ['HAPPY_ADDONS_VERSION'],
    'classes' => ['Happy_Addons\Elementor\Classes\Widgets_Manager'],
    'functions' => ['ha_has_pro'],
    'description' => 'Manage the Happy Elementor Addons pack on this site: list the widgets it contributes to the editor and switch any of them on or off, and work with its '
        . 'theme builder templates — headers, footers, single and archive layouts — including the pages each one is shown on. Also reports templates that were never opened '
        . 'in Elementor, or that are published with no display rules and so appear nowhere.',
    'templates' => [
        [
            'post_type' => 'ha_library',
            'kind_meta' => '_ha_library_type',
            'kinds' => ['header', 'footer', 'single', 'archive', 'loop-template'],
            // A loop template is placed by the theme rather than matched by a
            // display rule, so having none is correct for it and the audit must
            // not report it as a template that renders nowhere.
            'kinds_without_conditions' => ['loop-template'],
            // What the pack's own "Add New" stamps beside the builder flag: a
            // template created without the canvas page template previews with the
            // theme's header and footer wrapped around it.
            'create_meta' => ['_wp_page_template' => 'elementor_canvas'],
            'conditions' => [
                'display' => '_ha_display_cond',
            ],
        ],
    ],
    'after_template_write' => 'nibwp_happy_addons_refresh_conditions',
    // Three lists, all inverted, all separate options: the widgets that appear in
    // the editor, the editor features bolted onto Elementor's own controls, and
    // the site-wide extensions. One read/write pair reached only the first.
    'modules' => [
        'widget' => [
            'label' => 'widget',
            'read' => 'nibwp_happy_addons_widget_state',
            'write' => 'nibwp_happy_addons_set_widget',
        ],
        'feature' => [
            'label' => 'feature',
            'read' => 'nibwp_happy_addons_feature_state',
            'write' => 'nibwp_happy_addons_set_feature',
        ],
        'extension' => [
            'label' => 'extension',
            'read' => 'nibwp_happy_addons_extension_state',
            'write' => 'nibwp_happy_addons_set_extension',
        ],
    ],
    'notes' => [
        "THREE SEPARATE LISTS, all inverted: widget (what is in the editor),\n"
        . "feature (what is bolted onto Elementor's own controls) and extension\n"
        . "(site-wide). list_modules reports all three; pass group to narrow.\n"
        . "foreground-overlay and text-stroke are in BOTH the feature and the\n"
        . "extension list, under separate options, so an unqualified write on\n"
        . "either is refused until you say which with group - switching the wrong\n"
        . "one would look like it had worked.\n"
        . "A feature the add-on keeps on regardless of what is stored is reported\n"
        . "on and refuses a write; its always-on list includes paid features this\n"
        . "build does not otherwise carry.",
        "INVERTED WIDGET STORAGE: the pack keeps a list of the widgets that are\n"
        . "switched OFF, so list_modules already inverts it for you — 'enabled: true'\n"
        . "means the widget is available in the editor. Do not read the option\n"
        . "yourself and read it the other way round. A widget switched off vanishes\n"
        . "from the editor but stays in the saved data of any page already using it,\n"
        . "and that section then renders as nothing.",
        "DISPLAY RULES ARE CACHED SEPARATELY: the front end decides which template\n"
        . "to output from a cache the pack rebuilds only when its own Template\n"
        . "Conditions dialog is saved in the editor. update_template stores the new\n"
        . "rules on the template, but they do not take effect until that rebuild\n"
        . "happens, so after setting them open the template in Elementor and save\n"
        . "its Template Conditions once. Until then get_template reports the new\n"
        . "rules while the site still follows the old ones.",
        "RULE FORMAT: display is a flat list of slash-joined strings —\n"
        . "type/name/sub_name/sub_id, as in include/general, include/singular/post/12\n"
        . "or exclude/archive. Read a template that already works with get_template\n"
        . "and pass the same shape back rather than inventing one; the pack refuses\n"
        . "nothing here, it simply never matches.",
        "TEMPLATE KINDS: header, footer, single and archive are the slots the pack\n"
        . "offers. loop-template is a fifth kind this version's code recognises but\n"
        . "its own list of types does not offer — it arrives through the pack's\n"
        . "template-types filter, and it takes no display rules at all, so a loop\n"
        . "template is output by the widget that calls it rather than by a rule.",
        "A TEMPLATE CREATED HERE opens with the theme's own header and footer around\n"
        . "it, because the pack's own Add New also sets the page layout to Elementor\n"
        . "Canvas and create_template does not. Set the layout to Canvas in the\n"
        . "editor's page settings; it affects previewing the template on its own, not\n"
        . "how it is output as a header or footer.",
        "The pack keeps two more on/off lists with the same inversion, for its\n"
        . "editor extensions and its element features. Those are separate storage and\n"
        . "this integration's module list is the widget list only, so an extension or\n"
        . "a feature is switched from the pack's own settings screen.",
    ],
]);
