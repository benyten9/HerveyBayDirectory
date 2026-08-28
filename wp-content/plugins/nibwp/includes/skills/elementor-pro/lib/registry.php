<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Elementor Pro skill — LIVE registry introspection.
 *
 * The single source of truth for what widgets + controls exist on THIS install.
 * The agent must call these before authoring so it never guesses a widgetType or
 * control id (a wrong name is silently ignored by Elementor and renders nothing).
 *
 * Everything is read from Elementor's own managers at runtime — nothing is
 * hard-coded — so Pro, add-ons, and version differences are reflected exactly.
 */

/** Is Elementor active at all? */
function nibwp_elementor_pro_active(): bool
{
    return defined('ELEMENTOR_VERSION') && class_exists('\Elementor\Plugin');
}

/** Is Elementor Pro active? (gates form / posts / loop / woo / theme-builder widgets) */
function nibwp_elementor_pro_has_pro(): bool
{
    return defined('ELEMENTOR_PRO_VERSION');
}

/** The Elementor plugin instance, or null. */
function nibwp_elementor_pro_instance(): ?object
{
    return nibwp_elementor_pro_active() ? \Elementor\Plugin::instance() : null;
}

/**
 * All registered widget types on this site.
 *
 * @return array<int,array{name:string,title:string,categories:array,is_pro:bool}>
 */
function nibwp_elementor_pro_widget_types(): array
{
    $p = nibwp_elementor_pro_instance();
    if (!$p || !isset($p->widgets_manager)) {
        return [];
    }
    $out = [];
    foreach ((array) $p->widgets_manager->get_widget_types() as $name => $widget) {
        if (!is_object($widget)) {
            continue;
        }
        $class = get_class($widget);
        $out[] = [
            'name'       => (string) (method_exists($widget, 'get_name') ? $widget->get_name() : $name),
            'title'      => (string) (method_exists($widget, 'get_title') ? $widget->get_title() : $name),
            'categories' => method_exists($widget, 'get_categories') ? (array) $widget->get_categories() : [],
            'is_pro'     => str_starts_with($class, 'ElementorPro\\'),
        ];
    }
    usort($out, static fn ($a, $b) => strcmp($a['name'], $b['name']));
    return $out;
}

/** Just the valid widgetType names (for fast membership checks). */
function nibwp_elementor_pro_widget_names(): array
{
    return array_column(nibwp_elementor_pro_widget_types(), 'name');
}

function nibwp_elementor_pro_widget_exists(string $name): bool
{
    $p = nibwp_elementor_pro_instance();
    if (!$p || !isset($p->widgets_manager)) {
        return false;
    }
    return (bool) $p->widgets_manager->get_widget_types($name);
}

/**
 * Real control ids + types + defaults + responsive flag for one widget.
 * Skips UI-only controls (section/tab/divider/heading dividers).
 *
 * @return array{widget:string,exists:bool,is_pro:bool,controls:array}
 */
function nibwp_elementor_pro_widget_controls(string $name): array
{
    $p = nibwp_elementor_pro_instance();
    if (!$p || !isset($p->widgets_manager)) {
        return ['widget' => $name, 'exists' => false, 'is_pro' => false, 'controls' => []];
    }
    $widget = $p->widgets_manager->get_widget_types($name);
    if (!$widget || !method_exists($widget, 'get_controls')) {
        return ['widget' => $name, 'exists' => false, 'is_pro' => false, 'controls' => []];
    }
    $controls = [];
    $ui_only  = ['section', 'tab', 'tabs', 'divider', 'heading', 'raw_html', 'deprecated_notice', 'notice'];
    foreach ((array) $widget->get_controls() as $id => $ctrl) {
        $type = (string) ($ctrl['type'] ?? '');
        if (in_array($type, $ui_only, true)) {
            continue;
        }
        $entry = [
            'id'   => (string) $id,
            'type' => $type,
        ];
        if (array_key_exists('default', $ctrl) && !is_array($ctrl['default'])) {
            $entry['default'] = $ctrl['default'];
        }
        if (!empty($ctrl['options']) && is_array($ctrl['options'])) {
            $entry['options'] = array_keys($ctrl['options']);
        }
        // Responsive controls are stored as id + id_tablet + id_mobile.
        if (!empty($ctrl['responsive']) || !empty($ctrl['is_responsive'])) {
            $entry['responsive'] = true;
        }
        $controls[] = $entry;
    }
    return [
        'widget'   => $name,
        'exists'   => true,
        'is_pro'   => str_starts_with(get_class($widget), 'ElementorPro\\'),
        'controls' => $controls,
    ];
}

/**
 * Active responsive breakpoints (for the _tablet / _mobile suffix rules).
 * @return array<int,string>
 */
function nibwp_elementor_pro_breakpoints(): array
{
    $p = nibwp_elementor_pro_instance();
    $out = ['desktop'];
    if ($p && isset($p->breakpoints) && method_exists($p->breakpoints, 'get_active_breakpoints')) {
        foreach (array_keys((array) $p->breakpoints->get_active_breakpoints()) as $bp) {
            $out[] = (string) $bp;
        }
    } else {
        $out = ['desktop', 'tablet', 'mobile'];
    }
    return array_values(array_unique($out));
}

/** Does this site have the modern flexbox container experiment on? (containers vs legacy sections) */
function nibwp_elementor_pro_containers_active(): bool
{
    $p = nibwp_elementor_pro_instance();
    if ($p && isset($p->experiments) && method_exists($p->experiments, 'is_feature_active')) {
        // 'container' is the flexbox-container experiment; default-on in modern Elementor.
        return (bool) $p->experiments->is_feature_active('container');
    }
    return true;
}
