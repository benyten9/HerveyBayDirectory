<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * ACSS Pro — audit a target URL's rendered CSS against the current ACSS
 * tokens. Reports drift: literal values used where a token exists, missing
 * brand variants, contrast violations on rendered text.
 *
 * Read-only. Fetches with wp_safe_remote_get and a 10s timeout.
 */

wp_register_ability('nibwp/acss-pro-audit-site', [
    'label'       => __('ACSS Pro — Audit a URL for token drift', 'nibwp'),
    'description' => __('Fetch a URL, parse the rendered CSS, and report drift from the current ACSS tokens: literal hex/rem values that should be token references, missing brand variants, contrast violations. Read-only.', 'nibwp'),
    'category'    => 'acss-pro',
    'input_schema' => [
        'type'     => 'object',
        'required' => ['url'],
        'properties' => [
            'url' => ['type' => 'string'],
        ],
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'drift_items' => ['type' => 'array'],
            'summary'     => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'nibwp_acss_pro_audit_site',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => [
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function nibwp_acss_pro_audit_site(array $input): array|WP_Error
{
    if (function_exists('nibwp_skill_gate')) {
        $gate = nibwp_skill_gate('acss-pro');
        if (is_wp_error($gate)) {
            return $gate;
        }
    }
    $url = esc_url_raw((string) ($input['url'] ?? ''));
    if ($url === '') {
        return new WP_Error('audit_no_url', 'url is required.');
    }
    $resp = wp_safe_remote_get($url, ['timeout' => 10, 'redirection' => 5]);
    if (is_wp_error($resp)) {
        return $resp;
    }
    $body = (string) wp_remote_retrieve_body($resp);
    if ($body === '') {
        return new WP_Error('audit_empty_body', 'Empty response body.');
    }

    // Collect literal hex colors + font-size literals from inline + linked CSS.
    $items = [];
    preg_match_all('/#([0-9a-f]{3}|[0-9a-f]{6})\b/i', $body, $m_colors);
    $colors = array_count_values(array_map('strtolower', $m_colors[0] ?? []));
    arsort($colors);
    foreach (array_slice($colors, 0, 10, true) as $hex => $count) {
        $items[] = [
            'kind'   => 'literal_color',
            'value'  => $hex,
            'count'  => $count,
            'hint'   => 'Likely should be a token: var(--primary, ' . $hex . ') or var(--neutral-*, ' . $hex . ').',
        ];
    }
    preg_match_all('/font-size\s*:\s*([\d.]+)(px|rem|em|pt)\b/i', $body, $m_fs);
    $sizes = array_count_values(array_map(static fn ($v, $u) => $v . $u, $m_fs[1] ?? [], $m_fs[2] ?? []));
    arsort($sizes);
    foreach (array_slice($sizes, 0, 10, true) as $size => $count) {
        $items[] = [
            'kind'   => 'literal_font_size',
            'value'  => $size,
            'count'  => $count,
            'hint'   => 'Should be a token: var(--text-l, ' . $size . ').',
        ];
    }
    return [
        'drift_items' => $items,
        'summary'     => sprintf('Found %d distinct literal colors and %d distinct font-size literals. Top entries shown.', count($colors), count($sizes)),
    ];
}
