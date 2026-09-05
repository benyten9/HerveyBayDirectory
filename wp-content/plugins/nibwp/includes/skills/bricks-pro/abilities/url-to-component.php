<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Bricks Pro — URL → Bricks template (fetch + convert).
 *
 * Server fetches the URL (10s timeout, wp_safe_remote_get) and returns the
 * HTML + extracted style sheets to the agent, who then builds the payload
 * and submits via nibwp/bricks-pro-html-to-component.
 */

wp_register_ability('nibwp/bricks-pro-url-to-component', [
    'label'       => __('Bricks Pro — URL to Bricks template', 'nibwp'),
    'description' => __('Fetch a target URL, return its raw HTML + inline / linked stylesheets to the agent. Agent rebuilds the layout as a Bricks element tree, then submits via html-to-component. Read-only fetch — no persistence here.', 'nibwp'),
    'category'    => 'bricks-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'url'              => ['type' => 'string'],
            '_preflight_token' => ['type' => 'string'],
        ],
        'required' => ['url', '_preflight_token'],
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'html'        => ['type' => 'string'],
            'stylesheets' => ['type' => 'array'],
            'next_action' => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'nibwp_bricks_pro_url_to_component',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => [
            'instructions' => 'Fetches the URL HTML + every linked stylesheet so the agent has the full visual context. Run this, build the Bricks payload from the returned content, then submit via nibwp/bricks-pro-html-to-component.',
            'readonly'     => true,
            'destructive'  => false,
            'idempotent'   => true,
        ],
    ],
]);

function nibwp_bricks_pro_url_to_component(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('bricks-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }
    $raw_token = (string) ($input['_preflight_token'] ?? '');
    if (!function_exists('nibwp_skill_preflight_consume_token')) {
        require_once __DIR__ . '/../../../abilities/skill-preflight.php';
    }
    $token_payload = nibwp_skill_preflight_consume_token($raw_token, 'bricks-pro', $input);
    if (is_wp_error($token_payload)) {
        return $token_payload;
    }

    $url = esc_url_raw((string) ($input['url'] ?? ''));
    if ($url === '') {
        return new WP_Error('url_required', 'url is required.');
    }
    $resp = wp_safe_remote_get($url, ['timeout' => 10, 'redirection' => 5, 'user-agent' => 'Mozilla/5.0 NIBWP-BricksPro/1.0']);
    if (is_wp_error($resp)) {
        return $resp;
    }
    $html = (string) wp_remote_retrieve_body($resp);
    if ($html === '') {
        return new WP_Error('empty_body', 'Empty response body.');
    }

    // Extract <link rel="stylesheet"> hrefs BEFORE sanitization (they get stripped).
    $stylesheets = [];
    if (preg_match_all('/<link\b[^>]*\brel\s*=\s*["\']?stylesheet[^>]*\bhref\s*=\s*["\']([^"\']+)/i', $html, $m_link)) {
        $stylesheets = array_values(array_unique($m_link[1]));
    }

    // Sanitize the HTML — strip <script>, tracking pixels, ad iframes, analytics
    // snippets, comments, and noscript. The agent should be working with the
    // visual layout, not the runtime tracking layer.
    $sanitized = nibwp_bricks_pro_sanitize_fetched_html($html);

    return [
        'html'        => $sanitized,
        'stylesheets' => $stylesheets,
        'raw_size'    => strlen($html),
        'clean_size'  => strlen($sanitized),
        'next_action' => 'Build the Bricks payload from `html` (and optionally fetch the linked stylesheets agent-side for token extraction). Submit nibwp/bricks-pro-html-to-component with source.url=<url> + payload.',
    ];
}

/**
 * Strip noise from fetched HTML so the agent sees structure + content, not
 * scripts/ads/tracking. Conservative — preserves layout-bearing markup.
 */
function nibwp_bricks_pro_sanitize_fetched_html(string $html): string
{
    // 1. Strip every <script>…</script>.
    $html = (string) preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
    // 2. Strip <noscript>…</noscript>.
    $html = (string) preg_replace('#<noscript\b[^>]*>.*?</noscript>#is', '', $html);
    // 3. Strip <style>…</style> (CSS lives in linked stylesheets already extracted).
    $html = (string) preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html);
    // 4. Strip HTML comments (often hold tracking pixels OR breakouts).
    $html = (string) preg_replace('/<!--.*?-->/s', '', $html);
    // 5. Strip <link rel="preload|prefetch|dns-prefetch|preconnect|stylesheet"> — they're noise for layout extraction.
    $html = (string) preg_replace('#<link\b[^>]*\brel\s*=\s*["\']?(?:preload|prefetch|dns-prefetch|preconnect|stylesheet)[^>]*>#i', '', $html);
    // 6. Strip <meta> tags (no layout signal).
    $html = (string) preg_replace('#<meta\b[^>]*>#i', '', $html);
    // 7. Strip tracking pixel iframes (1x1 hidden iframes from common ad networks / analytics).
    $html = (string) preg_replace('#<iframe\b[^>]*\bsrc\s*=\s*["\'][^"\']*(?:doubleclick|googletagmanager|google-analytics|facebook\.com/tr|hotjar|fullstory|amplitude|segment|mixpanel|adservice|adnxs|criteo|taboola|outbrain)[^"\']*["\'][^>]*>(?:</iframe>)?#i', '', $html);
    // 8. Strip tracking <img> pixels.
    $html = (string) preg_replace('#<img\b[^>]*\bsrc\s*=\s*["\'][^"\']*(?:facebook\.com/tr|google-analytics|doubleclick|criteo|adnxs|taboola|outbrain)[^"\']*["\'][^>]*>#i', '', $html);
    // 9. Strip onclick=/onload=/onerror= inline event handlers anywhere.
    $html = (string) preg_replace('/\s+on[a-z]+\s*=\s*"[^"]*"/i', '', $html);
    $html = (string) preg_replace("/\s+on[a-z]+\s*=\s*'[^']*'/i", '', $html);
    // 10. Strip data-* analytics attrs (gtm, fb, hotjar, etc.).
    $html = (string) preg_replace('/\s+data-(?:gtm|ga|fbp|hj|amp|fullstory|segment|track|analytics|adnxs|criteo|taboola)[a-z0-9-]*\s*=\s*"[^"]*"/i', '', $html);
    // 11. Collapse runs of whitespace from the stripping pass.
    $html = (string) preg_replace('/\n\s*\n/', "\n", $html);
    return trim($html);
}
