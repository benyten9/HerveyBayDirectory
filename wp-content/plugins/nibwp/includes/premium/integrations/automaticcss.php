<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * AutomaticCSS (ACSS) integration — manage variables, classes, and framework settings.
 */

wp_register_ability('nibwp/acss-get-variables', [
    'label' => __('ACSS — Get CSS Variables', domain: 'nibwp'),
    'description' => __('Retrieve all AutomaticCSS custom properties (colors, spacing, typography, sizing). Returns the full variable map from ACSS settings.', domain: 'nibwp'),
    'category' => 'acss',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'group' => [
                'type' => 'string',
                'enum' => ['all', 'colors', 'spacing', 'typography', 'sizing', 'borders', 'shadows', 'custom'],
                'description' => 'Variable group to retrieve.',
                'default' => 'all',
            ],
        ],
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_acss_get_variables',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Get ACSS CSS custom properties.\nUse group='colors' for color palette, 'spacing' for spacing scale, 'typography' for font settings.\nReturns variable names and their values as defined in the ACSS dashboard.\nUse these values when building pages to stay consistent with the design system.",
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_acss_get_variables(array $input): array|WP_Error
{
    if (!defined('ACSS_PLUGIN_FILE')) {
        return new WP_Error('acss_not_active', 'AutomaticCSS plugin is not active.');
    }

    $group    = (string) ($input['group'] ?? 'all');
    $settings = nibwp_acss_read_settings();

    // ACSS stores settings as a flat array. Group them by prefix.
    $groups = [
        'colors' => [],
        'spacing' => [],
        'typography' => [],
        'sizing' => [],
        'borders' => [],
        'shadows' => [],
        'custom' => [],
    ];

    $color_prefixes = ['color-', 'primary', 'secondary', 'accent', 'base', 'neutral', 'shade', 'bg-', 'text-color'];
    $spacing_prefixes = ['space-', 'section-space', 'gap-', 'padding-', 'margin-', 'gutter'];
    $typo_prefixes = ['font-', 'text-', 'heading-', 'h1-', 'h2-', 'h3-', 'h4-', 'h5-', 'h6-', 'line-height', 'letter-spacing'];
    $sizing_prefixes = ['width-', 'max-width', 'min-height', 'container', 'content-width'];
    $border_prefixes = ['border-', 'radius-', 'rounded'];
    $shadow_prefixes = ['shadow-', 'box-shadow'];

    foreach ($settings as $key => $value) {
        $key_lower = strtolower($key);
        $categorized = false;

        foreach ([
            'colors' => $color_prefixes,
            'spacing' => $spacing_prefixes,
            'typography' => $typo_prefixes,
            'sizing' => $sizing_prefixes,
            'borders' => $border_prefixes,
            'shadows' => $shadow_prefixes,
        ] as $g => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_contains($key_lower, $prefix)) {
                    $groups[$g][$key] = $value;
                    $categorized = true;
                    break 2;
                }
            }
        }

        if (!$categorized) {
            $groups['custom'][$key] = $value;
        }
    }

    // An empty result means ACSS has nothing stored — say so, rather than let a
    // caller read zeros as "this site has no design system, safe to overwrite".
    $configured = $settings !== [];

    if ($group !== 'all' && array_key_exists($group, $groups)) {
        return [
            'success'    => true,
            'configured' => $configured,
            'group'      => $group,
            'data'       => $groups[$group],
            'count'      => count($groups[$group]),
        ];
    }

    $summary = [];
    foreach ($groups as $g => $vars) {
        $summary[$g] = count($vars);
    }

    return [
        'success'    => true,
        'configured' => $configured,
        'data'       => $groups,
        'summary'    => $summary,
        'total'      => count($settings),
    ];
}

wp_register_ability('nibwp/acss-update-variables', [
    'label' => __('ACSS — Update CSS Variables', domain: 'nibwp'),
    'description' => __('Update AutomaticCSS settings and CSS custom properties. Changes colors, spacing, typography, and other design tokens.', domain: 'nibwp'),
    'category' => 'acss',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'variables' => [
                'type' => 'object',
                'description' => 'Key-value pairs of ACSS settings to update.',
            ],
            'dry_run' => ['type' => 'boolean', 'default' => true, 'description' => 'Preview changes without saving.'],
        ],
        'required' => ['variables'],
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_acss_update_variables',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Update ACSS design tokens.\nALWAYS dry_run=true first to preview.\nKey names differ by ACSS version, so never guess them.\nUse acss-get-variables first to see current values and exact key names.",
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_acss_update_variables(array $input): array|WP_Error
{
    if (!defined('ACSS_PLUGIN_FILE')) {
        return new WP_Error('acss_not_active', 'AutomaticCSS plugin is not active.');
    }

    $variables = $input['variables'] ?? [];
    $dry_run = $input['dry_run'] ?? true;

    if (!is_array($variables) || $variables === []) {
        return new WP_Error('missing_variables', 'Provide variables object with key-value pairs.');
    }

    $settings = nibwp_acss_read_settings();
    if ($settings === []) {
        return new WP_Error(
            'acss_not_configured',
            'ACSS is active but has no stored settings to update. Configure it in the ACSS dashboard first.'
        );
    }

    $changes = [];
    foreach ($variables as $key => $value) {
        $old = $settings[$key] ?? null;
        $changes[] = [
            'key' => $key,
            'old_value' => $old,
            'new_value' => $value,
            // A key ACSS does not already define is almost always a wrong key
            // rather than a new token, so it is worth flagging on the preview.
            'is_new' => !array_key_exists($key, $settings),
        ];
        $settings[$key] = $value;
    }

    $written = null;
    if (!$dry_run) {
        $written = nibwp_acss_write_settings($settings, true);
        if (is_wp_error($written)) {
            return $written;
        }
    }

    return [
        'success' => true,
        'dry_run' => $dry_run,
        'changes' => $changes,
        'unknown_keys' => array_values(array_column(array_filter($changes, static fn($c) => $c['is_new']), 'key')),
        'written_via' => $written['via'] ?? null,
        'css_regenerated' => $written['regenerated'] ?? false,
        'message' => $dry_run
            ? count($changes) . ' changes previewed (dry run).'
            : count($changes) . ' variables updated.',
    ];
}

wp_register_ability('nibwp/acss-get-classes', [
    'label' => __('ACSS — List Utility Classes', domain: 'nibwp'),
    'description' => __('List all utility classes available in AutomaticCSS, grouped by category (layout, spacing, typography, colors, etc.).', domain: 'nibwp'),
    'category' => 'acss',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'search' => ['type' => 'string', 'description' => 'Filter classes by name.'],
            'category' => ['type' => 'string', 'description' => 'Filter by category (colors, typography, buttons, icons, forms, sizing, spacing, layout, accessibility, other).'],
        ],
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_acss_get_classes',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "List the ACSS utility classes this site actually compiled.\nNames are read from the generated stylesheet, so they match the installed ACSS version rather than a fixed list.\nCategories: colors, typography, buttons, icons, forms, sizing, spacing, layout, accessibility, other.\nSearch to find specific classes like 'btn--' or 'section--'.",
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_acss_get_classes(array $input): array|WP_Error
{
    if (!defined('ACSS_PLUGIN_FILE')) {
        return new WP_Error('acss_not_active', 'AutomaticCSS plugin is not active.');
    }

    $search = strtolower(trim((string) ($input['search'] ?? '')));
    $category = strtolower(trim((string) ($input['category'] ?? '')));

    // Read the classes this install actually compiled. A hardcoded list drifts
    // from the framework without any signal, and a class name that does not
    // exist produces a page that renders unstyled rather than an error.
    $classes = nibwp_acss_classes();
    if ($classes === []) {
        return new WP_Error(
            'acss_css_not_found',
            'No compiled ACSS stylesheet found to read classes from. Regenerate ACSS CSS and try again.'
        );
    }

    $class_groups = nibwp_acss_group_classes($classes);

    // Filter by category.
    if ($category !== '' && array_key_exists($category, $class_groups)) {
        $class_groups = [$category => $class_groups[$category]];
    }

    // Filter by search.
    if ($search !== '') {
        $filtered = [];
        foreach ($class_groups as $group => $classes) {
            $matched = array_filter($classes, static fn($c) => str_contains($c, $search));
            if ($matched !== []) {
                $filtered[$group] = array_values($matched);
            }
        }
        $class_groups = $filtered;
    }

    $total = 0;
    foreach ($class_groups as $classes) {
        $total += count($classes);
    }

    return ['success' => true, 'data' => $class_groups, 'total' => $total];
}

wp_register_ability('nibwp/acss-regenerate', [
    'label' => __('ACSS — Regenerate CSS', domain: 'nibwp'),
    'description' => __('Triggers AutomaticCSS to regenerate its compiled CSS files. Use after changing variables or settings.', domain: 'nibwp'),
    'category' => 'acss',
    'input_schema' => [
        'type' => 'object',
        'properties' => new stdClass(),
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_acss_regenerate',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Regenerate ACSS compiled CSS after changing settings. Run this after acss-update-variables.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_acss_regenerate(array $input): array|WP_Error
{
    if (!defined('ACSS_PLUGIN_FILE')) {
        return new WP_Error('acss_not_active', 'AutomaticCSS plugin is not active.');
    }

    $result = nibwp_acss_regenerate_css();
    if (is_wp_error($result)) {
        return $result;
    }

    return [
        'success'     => true,
        'regenerated' => $result['regenerated'],
        'via'         => $result['via'],
        'message'     => $result['regenerated']
            ? 'ACSS stylesheets recompiled.'
            : 'Regeneration signalled; ACSS exposed no settings model, so the CSS rebuilds on the next page load.',
    ];
}
