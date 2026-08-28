<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Voxel Pro — the vocabulary. Which widgets exist, what each one needs, where
 * a finished template can be assigned. Widget existence is read from the live
 * Elementor registry rather than a list in this file, so a Voxel upgrade that
 * renames a widget shows up here instead of in a failed build.
 */

require_once __DIR__ . '/../lib/registry.php';

wp_register_ability('nibwp/voxel-pro-catalog', [
    'label'       => __('Voxel Pro — widget and slot catalog', 'nibwp'),
    'description' => __('The vocabulary for building Voxel templates: which widgets exist on this site and what each requires, one widget\'s full control list read live from Elementor, and the slots a finished template can be assigned to.', 'nibwp'),
    'category'    => 'voxel-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'topic' => [
                'type' => 'string',
                'enum' => ['widgets', 'widget', 'slots', 'dtags'],
                'default' => 'widgets',
                'description' => 'widgets = the curated map with required settings. widget = one widget\'s live controls (pass "widget"). slots = where a template can be assigned. dtags = where to read the dynamic tag vocabulary.',
            ],
            'widget'    => ['type' => 'string', 'description' => 'Widget type for topic=widget, e.g. "ts-search-form".'],
            'post_type' => ['type' => 'string', 'description' => 'Scope filter keys and card slots to one Voxel post type.'],
        ],
        'additionalProperties' => false,
    ],
    'execute_callback'    => 'nibwp_voxel_pro_catalog_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => [
            'instructions' => "Call topic:'widgets' before composing a template, and topic:'widget' for any widget whose settings you are unsure of — those controls are read from this site, not from documentation. Dynamic tag names come from nibwp/voxel-render action:'catalog'.",
            'readonly' => true, 'destructive' => false, 'idempotent' => true,
        ],
    ],
]);

function nibwp_voxel_pro_catalog_execute(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('voxel-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }
    $guard = nibwp_voxel_pro_guard();
    if (is_wp_error($guard)) {
        return $guard;
    }

    $topic     = (string) ($input['topic'] ?? 'widgets');
    $post_type = sanitize_key((string) ($input['post_type'] ?? ''));

    switch ($topic) {
        case 'widget':
            return nibwp_voxel_pro_widget_controls((string) ($input['widget'] ?? ''));

        case 'slots':
            return nibwp_voxel_pro_slot_catalog($post_type);

        case 'dtags':
            return [
                'read_from' => 'nibwp/voxel-render',
                'how'       => 'Call nibwp/voxel-render { action: "catalog" } for every dynamic tag group and property this site exposes, and { action: "render", content, post_id } to check one before you use it.',
                'syntax'    => '@tags()@post(field_key.sub).modifier(arg)@endtags() — a value must begin with @tags() or Voxel treats it as literal text.',
                'note'      => 'voxel-pro-build renders every tag you send against a real post and refuses the build if one comes back unresolved, so a typo cannot reach the page.',
            ];

        case 'widgets':
        default:
            return nibwp_voxel_pro_widget_catalog($post_type);
    }
}
