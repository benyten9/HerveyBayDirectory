<?php

declare(strict_types=1);

/**
 * Mosaic Pro — the gate and the only door to the database.
 *
 * The skill converts, validates, recommends and orchestrates. Every write goes
 * through a Mosaic integration ability, which writes through Mosaic's own editor
 * model. No $wpdb write, wp_insert_post or update_post_meta in this skill —
 * tests/skills/mosaic-pro-check.php walks the tokens and fails if one appears.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** @return WP_Error|null */
function nibwp_mosaic_pro_gate(bool $writes = false)
{
    if (function_exists('nibwp_skill_gate')) {
        $gate = nibwp_skill_gate('mosaic-pro');
        if ($gate instanceof WP_Error) {
            return $gate;
        }
    }
    if (!function_exists('nibwp_mosaic_catalog') || !function_exists('nibwp_mosaic_normalize_tree')) {
        return new WP_Error(
            'nibwp_mosaic_pro_no_integration',
            __('The Mosaic integration is not loaded. Enable it under NibWP → Integrations; this skill builds through it and never writes on its own.', 'nibwp')
        );
    }
    if (function_exists('nibwp_mosaic_guard')) {
        $guard = nibwp_mosaic_guard();
        if ($guard instanceof WP_Error) {
            return $guard;
        }
    }
    if ($writes && function_exists('current_user_can') && !current_user_can('edit_theme_options')) {
        return new WP_Error(
            'nibwp_mosaic_pro_denied',
            __('Building in Mosaic changes how the site renders, which needs the edit_theme_options capability.', 'nibwp')
        );
    }

    return null;
}

/**
 * Run a Mosaic integration ability. Recorded so the check can count writes.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_mosaic_pro_delegate(string $slug, array $input)
{
    if (!str_starts_with($slug, 'mosaic-') || str_starts_with($slug, 'mosaic-pro-')) {
        return new WP_Error('nibwp_mosaic_pro_bad_delegate', sprintf(
            /* translators: %s: ability slug */
            __('"%s" is not a Mosaic integration ability.', 'nibwp'),
            $slug
        ));
    }

    $GLOBALS['nibwp_mosaic_pro_delegated'][] = ['ability' => 'nibwp/' . $slug, 'input' => $input];

    if (function_exists('wp_get_ability')) {
        $ability = wp_get_ability('nibwp/' . $slug);
        if (is_object($ability) && method_exists($ability, 'execute')) {
            $result = $ability->execute($input);

            return is_array($result) || $result instanceof WP_Error ? $result : ['result' => $result];
        }
    }

    $callback = 'nibwp_mosaic_ability_' . str_replace('-', '_', substr($slug, strlen('mosaic-')));
    if (function_exists($callback)) {
        $result = $callback($input);

        return is_array($result) || $result instanceof WP_Error ? $result : ['result' => $result];
    }

    return new WP_Error('nibwp_mosaic_pro_no_integration', sprintf(
        /* translators: %s: ability name */
        __('The Mosaic integration ability %s is not available. Enable the Mosaic integration; this skill will not write to the database itself.', 'nibwp'),
        'nibwp/' . $slug
    ));
}

/** Shared ability registration for the skill. */
function nibwp_mosaic_pro_register(string $slug, string $label, string $description, array $properties, string $callback, bool $readonly, array $instructions, array $required = []): void
{
    $schema = ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
    if ($required !== []) {
        $schema['required'] = $required;
    }
    wp_register_ability('nibwp/' . $slug, [
        'label'               => $label,
        'description'         => $description,
        'category'            => 'mosaic-pro',
        'input_schema'        => $schema,
        'output_schema'       => ['type' => 'object'],
        'execute_callback'    => $callback,
        'permission_callback' => 'nibwp_permission_callback',
        'meta'                => [
            'show_in_rest' => true,
            'mcp'          => ['public' => true],
            'annotations'  => [
                'instructions' => implode("\n", $instructions),
                'readonly'     => $readonly,
                'destructive'  => false,
                'idempotent'   => $readonly,
            ],
        ],
    ]);
}

/**
 * What the validator should check names against on this site.
 *
 * @param string[] $new_classes class names this build will create
 */
function nibwp_mosaic_pro_context(array $input, array $new_classes = []): array
{
    $context = ['new_classes' => $new_classes, 'live_types' => function_exists('nibwp_mosaic_active') && nibwp_mosaic_active()];
    if (function_exists('nibwp_mosaic_resolve_theme') && function_exists('nibwp_mosaic_installed') && nibwp_mosaic_installed()) {
        $theme = nibwp_mosaic_resolve_theme((string) ($input['theme_id'] ?? ''));
        if (is_string($theme)) {
            $context['theme_id'] = $theme;
            $context['classes'] = array_keys(nibwp_mosaic_class_ids_by_name($theme));
            $context['components'] = array_column(nibwp_mosaic_list_components($theme), 'id');
        }
    }

    return $context;
}
