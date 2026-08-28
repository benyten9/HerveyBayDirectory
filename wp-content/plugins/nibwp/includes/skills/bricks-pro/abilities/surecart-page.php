<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/../lib/validator.php';
// _shared engine is shipped by BOTH the etchwp-pro and bricks-pro skills; when
// each runs from its own plugin dir the paths differ, so require_once cannot
// dedup and PHP would redeclare the early-bound functions. Guard on a sentinel.
$nibwp_sc_engine = __DIR__ . '/../../_shared/surecart/engine.php';
if (!function_exists('nibwp_surecart_templates') && file_exists($nibwp_sc_engine)) {
    require_once $nibwp_sc_engine;
}

/**
 * Bricks Pro × SureCart — build a SureCart storefront section as a Bricks
 * template. Shares the SureCart templates engine with the EtchWP Pro skill;
 * this ability emits + validates + persists for Bricks. The SureCart blocks
 * render through a Bricks code element (do_blocks), wrapped in a styled section.
 */
wp_register_ability('nibwp/bricks-pro-surecart', [
    'label'       => __('Bricks Pro — SureCart Section', 'nibwp'),
    'description' => __('Design a SureCart storefront section (buy-button, product, product-grid, pricing, checkout, dashboard) as a Bricks template — the SureCart blocks hosted in a styled Bricks section. Always dry_run:true first. Bind with price_id / product_id / products / collection_id.', 'nibwp'),
    'category'    => 'bricks-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'template'      => ['type' => 'string', 'enum' => ['buy-button', 'product', 'product-grid', 'pricing', 'checkout', 'dashboard']],
            'params'        => ['type' => 'object'],
            'title'         => ['type' => 'string'],
            'template_type' => ['type' => 'string', 'enum' => ['section', 'content', 'header', 'footer'], 'default' => 'section'],
            'dry_run'       => ['type' => 'boolean', 'default' => true],
            '_preflight_token' => ['type' => 'string'],
        ],
        'required' => ['template'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_bricks_surecart_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false, 'instructions' => 'Use nibwp/surecart-products for real product_id/price_id. dry_run:true returns the validated Bricks payload; dry_run:false creates a Bricks template. The SureCart blocks render via a code element (requires Bricks code execution enabled).']],
]);

function nibwp_bricks_surecart_execute(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('bricks-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }
    $token = (string) ($input['_preflight_token'] ?? '');
    $answers = nibwp_skill_preflight_consume_token($token, 'bricks-pro');
    if (is_wp_error($answers)) {
        return $answers;
    }
    $answers = is_array($answers) ? $answers : [];

    if (!function_exists('nibwp_surecart_build')) {
        return new WP_Error('surecart_engine_missing', 'The shared SureCart templates engine is not available in this build.', ['status' => 409]);
    }

    $template = (string) ($input['template'] ?? '');
    $params   = (array) ($input['params'] ?? []);
    $brand    = sanitize_key((string) ($params['brand'] ?? $answers['brand_prefix'] ?? 'sc'));
    $params['brand'] = $brand;

    $built = nibwp_surecart_build($template, $params, 'bricks');
    if (is_wp_error($built)) {
        return $built;
    }
    $payload = $built['payload'];

    $verdict = function_exists('nibwp_bricks_pro_validate_payload')
        ? nibwp_bricks_pro_validate_payload($payload, ['template_type' => (string) ($input['template_type'] ?? 'section')])
        : ['passed' => true, 'failed' => [], 'warnings' => []];

    if (!empty($input['dry_run'])) {
        return ['dry_run' => true, 'passed' => $verdict['passed'], 'failed' => $verdict['failed'], 'warnings' => $verdict['warnings'], 'surecart_markup' => $built['meta']['surecart_markup'] ?? '', 'payload' => $payload];
    }
    if (!$verdict['passed']) {
        return new WP_Error('validation_failed', 'SureCart section failed Bricks validation — fix and resubmit.', ['status' => 422, 'failed' => $verdict['failed']]);
    }
    if (!function_exists('nibwp_bricks_create_template')) {
        return new WP_Error('bricks_unavailable', 'The Bricks integration (nibwp_bricks_create_template) is not loaded — activate Bricks + the Bricks integration.', ['status' => 409]);
    }

    // Merge global classes into the Bricks option. Shared with the
    // html-to-component flow: this used to append the payload entry verbatim,
    // so a class without an id landed in the option Bricks reads unguarded.
    require_once __DIR__ . '/../lib/global-classes.php';
    nibwp_bricks_merge_global_classes((array) ($payload['global_classes'] ?? []));

    $res = nibwp_bricks_create_template([
        'title'         => sanitize_text_field((string) ($input['title'] ?? ('SureCart ' . $template))),
        'template_type' => (string) ($input['template_type'] ?? 'section'),
        'elements'      => $payload['elements'],
    ]);
    if (is_wp_error($res)) {
        return $res;
    }
    nibwp_skill_preflight_clear_token($token);
    return ['persisted' => true, 'template' => $template] + (array) $res;
}
