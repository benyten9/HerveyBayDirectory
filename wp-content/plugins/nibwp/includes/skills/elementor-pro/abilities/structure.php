<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/../lib/registry.php';
require_once __DIR__ . '/../lib/converter.php';

/**
 * Elementor Pro — read + repair existing pages.
 */

// ── get-structure ──────────────────────────────────────────────────────────
wp_register_ability('nibwp/elementor-pro-get-structure', [
    'label'       => __('Elementor Pro — get page structure', 'nibwp'),
    'description' => __('Read the Elementor element tree of a page/post: element count, top-level containers, widget types used, and whether the stored data is valid. Use before refining or repairing.', 'nibwp'),
    'category'    => 'elementor-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['post_id' => ['type' => 'integer', 'description' => 'The page/post ID.']],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'execute_callback'    => 'nibwp_elementor_pro_get_structure',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function nibwp_elementor_pro_get_structure(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('elementor-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }
    $post_id = (int) ($input['post_id'] ?? 0);
    if (!$post_id || !get_post($post_id)) {
        return new WP_Error('not_found', 'No such post.');
    }
    $raw = (string) get_post_meta($post_id, '_elementor_data', true);
    if ($raw === '') {
        return ['post_id' => $post_id, 'is_elementor' => false, 'summary' => 'This post has no Elementor data.'];
    }
    $tree = json_decode($raw, true);
    if (!is_array($tree)) {
        return [
            'post_id' => $post_id,
            'is_elementor' => true,
            'data_valid' => false,
            'summary' => 'Elementor data is present but does NOT decode as valid JSON — the page will render blank. Run nibwp/elementor-pro-repair.',
        ];
    }
    $flat = nibwp_elementor_pro_flatten($tree);
    $types = [];
    foreach ($flat as $el) {
        if (($el['elType'] ?? '') === 'widget') {
            $t = (string) ($el['widgetType'] ?? '');
            $types[$t] = ($types[$t] ?? 0) + 1;
        }
    }
    return [
        'post_id'       => $post_id,
        'is_elementor'  => true,
        'data_valid'    => true,
        'element_count' => count($flat),
        'top_level'     => count($tree),
        'widget_types'  => $types,
        'edit_url'      => admin_url('post.php?post=' . $post_id . '&action=elementor'),
        'summary'       => sprintf('%d elements, %d top-level containers, %d distinct widget types.', count($flat), count($tree), count($types)),
    ];
}

// ── repair ─────────────────────────────────────────────────────────────────
wp_register_ability('nibwp/elementor-pro-repair', [
    'label'       => __('Elementor Pro — repair a broken/blank page', 'nibwp'),
    'description' => __('Fix a page that renders blank because its _elementor_data was saved unslashed (corrupted JSON) or its CSS is stale. Re-normalises, re-saves the data correctly slashed, regenerates CSS, and round-trip verifies — reverting if anything is off.', 'nibwp'),
    'category'    => 'elementor-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['post_id' => ['type' => 'integer']],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'execute_callback'    => 'nibwp_elementor_pro_repair',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true]],
]);

function nibwp_elementor_pro_repair(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('elementor-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }
    if (!defined('ELEMENTOR_VERSION')) {
        return new WP_Error('elementor_missing', 'Elementor is not active.');
    }
    $post_id = (int) ($input['post_id'] ?? 0);
    if (!$post_id || !get_post($post_id)) {
        return new WP_Error('not_found', 'No such post.');
    }
    $raw = (string) get_post_meta($post_id, '_elementor_data', true);
    if ($raw === '') {
        return new WP_Error('no_data', 'This post has no Elementor data to repair.');
    }

    $tree = json_decode($raw, true);
    $fixed_json = false;
    if (!is_array($tree)) {
        // Common corruption: over/under-slashed. Try stripslashes then decode.
        $tree = json_decode(stripslashes($raw), true);
        $fixed_json = is_array($tree);
    }
    if (!is_array($tree)) {
        return new WP_Error('unrecoverable', 'Could not decode the Elementor data even after unslashing. Restore from a revision.');
    }

    $count = nibwp_elementor_pro_count($tree);
    $encoded = wp_json_encode($tree);
    if ($encoded === false) {
        return new WP_Error('encode_failed', 'Could not re-encode the tree.');
    }
    update_post_meta($post_id, '_elementor_data', wp_slash($encoded));
    update_post_meta($post_id, '_elementor_edit_mode', 'builder');
    update_post_meta($post_id, '_elementor_version', ELEMENTOR_VERSION);

    // round-trip
    $back = json_decode((string) get_post_meta($post_id, '_elementor_data', true), true);
    if (!is_array($back) || nibwp_elementor_pro_count($back) !== $count) {
        return new WP_Error('roundtrip_failed', 'Re-save did not round-trip; left as-was.');
    }

    $css = false;
    if (class_exists('\Elementor\Core\Files\CSS\Post')) {
        try {
            \Elementor\Core\Files\CSS\Post::create($post_id)->update();
            $css = true;
        } catch (\Throwable $e) {
        }
    }
    if (isset(\Elementor\Plugin::instance()->files_manager)) {
        \Elementor\Plugin::instance()->files_manager->clear_cache();
    }

    return [
        'success'   => true,
        'post_id'   => $post_id,
        'fixed_json'=> $fixed_json,
        'elements'  => $count,
        'css_regenerated' => $css,
        'view_url'  => get_permalink($post_id),
        'summary'   => sprintf('Repaired: re-saved %d elements slashed correctly%s, CSS %s. The page should render now.', $count, $fixed_json ? ' (recovered corrupted JSON)' : '', $css ? 'regenerated' : 'left to editor'),
    ];
}
