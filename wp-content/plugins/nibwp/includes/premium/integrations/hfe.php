<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: the Elementor header, footer and blocks builder.
 *
 * Templates are `elementor-hf` posts. `ehf_template_type` says which of the
 * four slots a template fills, and three meta keys hold the display rules. The
 * widget list is the plugin's own `get_widget_list()`, whose on/off state lives
 * in the `_hfe_widgets` option as slug => slug for on and slug => 'disabled'
 * for off; a slug that is absent falls back to the widget's own `default`.
 *
 * The read below deliberately rebuilds that state from the option rather than
 * calling `get_widget_options()`, which memoises into a static and would hand
 * back the pre-write value inside the same request.
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
 * The widget slugs this pack offers and whether each is switched on.
 *
 * @return array<string, bool>|WP_Error
 */
function nibwp_hfe_widget_state(): array|WP_Error
{
    if (!class_exists('HFE_Helper') || !method_exists('HFE_Helper', 'get_widget_list')) {
        return new WP_Error(
            'nibwp_hfe_no_widget_list',
            __('This version of the header and footer builder does not expose a widget list.', 'nibwp'),
            ['status' => 400]
        );
    }

    $list = HFE_Helper::get_widget_list();
    $saved = get_option('_hfe_widgets', []);
    $saved = is_array($saved) ? $saved : [];
    $state = [];

    foreach ((array) $list as $slug => $data) {
        $slug = (string) $slug;

        if (array_key_exists($slug, $saved)) {
            $state[$slug] = $saved[$slug] !== 'disabled';
            continue;
        }

        $state[$slug] = (bool) (is_array($data) ? ($data['default'] ?? false) : false);
    }

    return $state;
}

/**
 * Switch one widget on or off, in the plugin's own storage format.
 */
function nibwp_hfe_set_widget(string $slug, bool $on): bool|WP_Error
{
    $state = nibwp_hfe_widget_state();

    if ($state instanceof WP_Error) {
        return $state;
    }

    if (!array_key_exists($slug, $state)) {
        return new WP_Error(
            'nibwp_hfe_unknown_widget',
            __('That widget is not one this add-on offers.', 'nibwp'),
            ['status' => 400]
        );
    }

    $saved = get_option('_hfe_widgets', []);
    $saved = is_array($saved) ? $saved : [];
    $saved[$slug] = $on ? $slug : 'disabled';

    update_option('_hfe_widgets', array_map('esc_attr', $saved));

    return true;
}

nibwp_elpack_register([
    'key' => 'hfe',
    'name' => 'Elementor Header & Footer Builder',
    'dir' => 'header-footer-elementor',
    'constants' => ['HFE_VER'],
    'classes' => ['HFE_Helper'],
    'description' => 'Manage the Elementor header, footer and block templates on this site: list them, create one, set which pages and roles it appears on, '
        . 'and switch the builder\'s own widgets on or off. Also reports which templates are published with no display rules, so they render nowhere.',
    'templates' => [
        [
            'post_type' => 'elementor-hf',
            'kind_meta' => 'ehf_template_type',
            'kinds' => ['type_header', 'type_before_footer', 'type_footer', 'custom'],
            'conditions' => [
                'include' => 'ehf_target_include_locations',
                'exclude' => 'ehf_target_exclude_locations',
                'roles' => 'ehf_target_user_roles',
            ],
        ],
    ],
    'modules' => [
        'label' => 'widget',
        'read' => 'nibwp_hfe_widget_state',
        'write' => 'nibwp_hfe_set_widget',
    ],
    'notes' => [
        "TEMPLATE KINDS: type_header, type_footer, type_before_footer and custom.\n"
        . "A template with no kind set is built but never output anywhere; 'custom'\n"
        . "is output by its own shortcode rather than by a display rule.",
        "DISPLAY RULES: include and exclude hold the plugin's own location\n"
        . "structure — read one that already works with get_template before writing\n"
        . "a new one, and pass the same shape back. roles limits a template to\n"
        . "certain user roles and is separate from the locations.",
    ],
]);
