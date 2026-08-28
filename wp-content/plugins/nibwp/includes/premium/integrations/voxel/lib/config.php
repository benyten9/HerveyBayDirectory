<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Voxel integration — shared plumbing.
 *
 * Voxel has no public API: private constructors, memoized singletons, a
 * namespace that only exists after after_setup_theme, and zero REST routes.
 * Everything in this integration therefore goes through two disciplines that
 * live here so no call site can skip them:
 *
 *   - nibwp_voxel_guard(): every ability entry point checks the theme is
 *     really present before touching a \Voxel\ symbol.
 *   - nibwp_voxel_try(): every call into Voxel internals runs inside a
 *     Throwable trap, because an internal API verified against 1.7.8.6 may
 *     throw anything at all on 1.8 — and an ability must answer with a
 *     WP_Error, never a fatal.
 */

/** Is the Voxel theme active (parent or child)? */
function nibwp_voxel_available(): bool
{
    if (class_exists('\\Voxel\\Post')) {
        return true;
    }

    $theme = wp_get_theme();

    return $theme->get_template() === 'voxel' || $theme->get_stylesheet() === 'voxel';
}

/**
 * Refuse early when Voxel is not actually loaded.
 *
 * Detection by theme name alone is not enough to CALL Voxel: the classes only
 * exist after after_setup_theme, and an ability registered at abilities-init
 * can in principle be executed in a context where the theme did not boot.
 */
function nibwp_voxel_guard(): ?WP_Error
{
    if (!class_exists('\\Voxel\\Post') || !function_exists('\\Voxel\\get')) {
        return nibwp_voxel_err(
            'voxel_unavailable',
            'The Voxel theme is not loaded on this request. It must be the active theme (or parent of the active child theme).',
            404
        );
    }

    return null;
}

/** House WP_Error wrapper. */
function nibwp_voxel_err(string $code, string $message, int $status = 400, array $extra = []): WP_Error
{
    return new WP_Error($code, $message, array_merge(['status' => $status], $extra));
}

/**
 * Run a closure against Voxel internals; a Throwable becomes a WP_Error.
 *
 * @return mixed|WP_Error
 */
function nibwp_voxel_try(callable $fn)
{
    try {
        return $fn();
    } catch (\Throwable $e) {
        return nibwp_voxel_err('voxel_internal_error', $e->getMessage(), 500);
    }
}

/** Clamp pagination to sane bounds. */
function nibwp_voxel_paginate(array $input): array
{
    return [
        min(max((int) ($input['per_page'] ?? 20), 1), 100),
        max((int) ($input['page'] ?? 1), 1),
    ];
}

/**
 * Voxel-managed post type or error. The error names every valid key, so an
 * agent that guessed wrong learns the whole vocabulary from one refusal.
 *
 * @return \Voxel\Post_Type|WP_Error
 */
function nibwp_voxel_post_type(string $key)
{
    if ($key === '') {
        return nibwp_voxel_err('no_post_type', 'Provide a "post_type" key. See nibwp/voxel-info for the list.');
    }

    $pt = nibwp_voxel_try(static fn () => \Voxel\Post_Type::get($key));
    if (is_wp_error($pt)) {
        return $pt;
    }

    if ($pt === null || !$pt->is_managed_by_voxel()) {
        $valid = nibwp_voxel_try(static fn () => array_map(
            static fn ($t) => $t->get_key(),
            \Voxel\Post_Type::get_voxel_types()
        ));

        return nibwp_voxel_err(
            'unknown_post_type',
            sprintf('"%s" is not a Voxel-managed post type.', $key),
            404,
            ['valid_post_types' => is_wp_error($valid) ? [] : array_values($valid)]
        );
    }

    return $pt;
}
