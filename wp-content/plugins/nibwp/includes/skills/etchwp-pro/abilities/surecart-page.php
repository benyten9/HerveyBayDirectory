<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/../lib/validator.php';
require_once __DIR__ . '/../lib/persister.php';
// _shared engine is shipped by BOTH the etchwp-pro and bricks-pro skills; when
// each runs from its own plugin dir the paths differ, so require_once cannot
// dedup and PHP would redeclare the early-bound functions. Guard on a sentinel.
$nibwp_sc_engine = __DIR__ . '/../../_shared/surecart/engine.php';
if (!function_exists('nibwp_surecart_templates') && file_exists($nibwp_sc_engine)) {
    require_once $nibwp_sc_engine;
}

/**
 * EtchWP Pro × SureCart — build a styled SureCart storefront section as a
 * validated Etch component. Shares the SureCart templates engine with the
 * Bricks Pro skill; this ability emits + validates + persists for Etch.
 */
wp_register_ability('nibwp/etchwp-pro-surecart', [
    'label'       => __('EtchWP Pro — SureCart Section', 'nibwp'),
    'description' => __('Design a SureCart storefront section (buy-button, product, product-grid, pricing, checkout, dashboard) as a validated EtchWP component — the SureCart blocks wrapped in a token-styled Etch section. Always dry_run:true first. templates come from the shared SureCart engine; bind with price_id / product_id / products / collection_id.', 'nibwp'),
    'category'    => 'etchwp-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'template' => ['type' => 'string', 'enum' => ['buy-button', 'product', 'product-grid', 'pricing', 'checkout', 'dashboard']],
            'params'   => ['type' => 'object', 'description' => 'price_id | product_id | products[] | collection_id | columns | limit | label.'],
            'target'   => ['type' => 'object', 'description' => '{ post_id, mode: append|replace_section|new_page, new_page_title? }'],
            'dry_run'  => ['type' => 'boolean', 'default' => true],
            '_preflight_token' => ['type' => 'string'],
        ],
        'required' => ['template'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_etchwp_surecart_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false, 'instructions' => 'List options via nibwp/surecart-blocks + nibwp/surecart-products to get real product_id/price_id. dry_run:true returns the validated payload; dry_run:false persists into the target post.']],
]);

function nibwp_etchwp_surecart_execute(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('etchwp-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }
    $token = (string) ($input['_preflight_token'] ?? '');
    $answers = nibwp_skill_preflight_consume_token($token, 'etchwp-pro');
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

    $built = nibwp_surecart_build($template, $params, 'etch');
    if (is_wp_error($built)) {
        return $built;
    }
    $payload = $built['payload'];

    $verdict = nibwp_etchwp_validate_payload($payload, ['brand' => $brand, 'element_type' => 'section']);
    if (!empty($input['dry_run'])) {
        return ['dry_run' => true, 'passed' => $verdict['passed'], 'failed' => $verdict['failed'], 'warnings' => $verdict['warnings'], 'surecart_markup' => $built['meta']['surecart_markup'] ?? '', 'payload' => $payload];
    }
    if (!$verdict['passed']) {
        return new WP_Error('validation_failed', 'SureCart section failed Etch validation — fix and resubmit.', ['status' => 422, 'failed' => $verdict['failed']]);
    }
    $target = (array) ($input['target'] ?? []);
    $res = nibwp_etchwp_persist_payload($payload, $target);
    if (is_wp_error($res)) {
        return $res;
    }
    nibwp_skill_preflight_clear_token($token);
    return ['persisted' => true, 'template' => $template] + (array) $res;
}
