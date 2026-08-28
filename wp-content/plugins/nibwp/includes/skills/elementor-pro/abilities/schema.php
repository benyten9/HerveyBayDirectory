<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/../lib/registry.php';

/**
 * Elementor Pro — live registry abilities.
 *
 * The agent MUST call these before authoring so it never guesses a widgetType or
 * a control id. Everything is read from Elementor's own managers on this site,
 * so Pro / add-on / version differences are reflected exactly.
 */

// ── list-widgets ───────────────────────────────────────────────────────────
wp_register_ability('nibwp/elementor-pro-list-widgets', [
    'label'       => __('Elementor Pro — list available widgets', 'nibwp'),
    'description' => __('The real widget types registered on THIS site (core + Pro + add-ons), plus Pro status, active breakpoints, and whether flexbox containers are on. Call before authoring; never invent a widgetType.', 'nibwp'),
    'category'    => 'elementor-pro',
    'input_schema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
    'execute_callback'    => 'nibwp_elementor_pro_list_widgets',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function nibwp_elementor_pro_list_widgets(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('elementor-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }
    if (!nibwp_elementor_pro_active()) {
        return new WP_Error('elementor_missing', 'Elementor is not active on this site.');
    }
    $widgets = nibwp_elementor_pro_widget_types();
    return [
        'elementor_version' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '',
        'pro_active'        => nibwp_elementor_pro_has_pro(),
        'containers_active' => nibwp_elementor_pro_containers_active(),
        'breakpoints'       => nibwp_elementor_pro_breakpoints(),
        'widget_count'      => count($widgets),
        'widgets'           => $widgets,
        'summary'           => sprintf('%d widgets available (%s). Get control ids per widget with nibwp/elementor-pro-widget-schema before setting any settings.', count($widgets), nibwp_elementor_pro_has_pro() ? 'Pro active' : 'free only'),
    ];
}

// ── widget-schema ──────────────────────────────────────────────────────────
wp_register_ability('nibwp/elementor-pro-widget-schema', [
    'label'       => __('Elementor Pro — widget control schema', 'nibwp'),
    'description' => __('The real control ids + types + defaults + responsive flags for one widget on THIS site. A setting keyed by anything other than a real control id is silently ignored by Elementor, so confirm names here before authoring.', 'nibwp'),
    'category'    => 'elementor-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'widget' => ['type' => 'string', 'description' => 'widgetType, e.g. "heading", "image", "button", "icon-box". From nibwp/elementor-pro-list-widgets.'],
        ],
        'required' => ['widget'],
        'additionalProperties' => false,
    ],
    'execute_callback'    => 'nibwp_elementor_pro_widget_schema',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function nibwp_elementor_pro_widget_schema(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('elementor-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }
    if (!nibwp_elementor_pro_active()) {
        return new WP_Error('elementor_missing', 'Elementor is not active on this site.');
    }
    $widget = trim((string) ($input['widget'] ?? ''));
    if ($widget === '') {
        return new WP_Error('no_widget', 'Provide a widget name.');
    }
    if (!nibwp_elementor_pro_widget_exists($widget)) {
        return new WP_Error('unknown_widget', sprintf('No widget "%s" on this site. Use nibwp/elementor-pro-list-widgets.', $widget));
    }
    $schema = nibwp_elementor_pro_widget_controls($widget);
    $schema['breakpoints'] = nibwp_elementor_pro_breakpoints();
    $schema['note'] = 'Responsive controls store as <id>, <id>_tablet, <id>_mobile. Group controls (typography, border, box_shadow) expand into <id>_font_size etc. Prefer __globals__ for global colors/fonts and __dynamic__ for dynamic tags.';
    return $schema;
}
