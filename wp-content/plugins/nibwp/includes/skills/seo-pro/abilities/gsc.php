<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/../lib/external.php';

wp_register_ability('nibwp/seo-pro-gsc', [
    'label'       => __('SEO Pro — Search Console', 'nibwp'),
    'description' => __('Surface optimization opportunities from Google Search Console: pages with high impressions but low CTR or ranking just off page one. Actions: opportunities, configure (store the OAuth access token + property URL). Requires a connected Google account.', 'nibwp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'        => ['type' => 'string', 'enum' => ['opportunities', 'configure']],
            'access_token'  => ['type' => 'string', 'description' => 'For configure: a Search Console OAuth access token.'],
            'site_url'      => ['type' => 'string', 'description' => 'For configure: the GSC property (e.g. https://example.com/ or sc-domain:example.com).'],
            'days'          => ['type' => 'integer', 'default' => 28],
            'min_impressions' => ['type' => 'integer', 'default' => 50],
            'max_ctr'       => ['type' => 'number', 'default' => 0.02],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_seo_pro_gsc_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => nibwp_seo_pro_ability_meta(true, false, 'Read-only. Returns pages worth optimizing (high impressions, low CTR). Feed them into seo-pro-optimize.'),
]);

function nibwp_seo_pro_gsc_execute(array $input): array|WP_Error
{
    $g = nibwp_seo_pro_guard($input, false);
    if (is_wp_error($g)) {
        return $g;
    }
    $action = (string) ($input['action'] ?? '');

    if ($action === 'configure') {
        $token = (string) ($input['access_token'] ?? '');
        $site  = (string) ($input['site_url'] ?? home_url('/'));
        if ($token === '') {
            return nibwp_seo_pro_err('no_token', 'Provide an "access_token".');
        }
        nibwp_seo_pro_set_cred('gsc', ['access_token' => $token, 'site_url' => $site]);
        return ['configured' => true, 'site_url' => $site];
    }

    // opportunities
    $cred = nibwp_seo_pro_cred('gsc');
    if (!is_array($cred) || empty($cred['access_token'])) {
        return nibwp_seo_pro_no_cred('Google Search Console', 'Connect Google and call action:configure with the OAuth access_token + property URL.');
    }
    $site = (string) ($cred['site_url'] ?? home_url('/'));
    $days = max(7, min((int) ($input['days'] ?? 28), 480));
    $end  = gmdate('Y-m-d');
    $start = gmdate('Y-m-d', time() - $days * DAY_IN_SECONDS);

    $res = nibwp_seo_pro_http(
        'https://searchconsole.googleapis.com/webmasters/v3/sites/' . rawurlencode($site) . '/searchAnalytics/query',
        [
            'method'  => 'POST',
            'headers' => ['Authorization' => 'Bearer ' . $cred['access_token'], 'Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['startDate' => $start, 'endDate' => $end, 'dimensions' => ['page'], 'rowLimit' => 250]),
        ]
    );
    if (is_wp_error($res)) {
        return $res;
    }

    $min_impr = (int) ($input['min_impressions'] ?? 50);
    $max_ctr  = (float) ($input['max_ctr'] ?? 0.02);
    $opps = [];
    foreach ((array) ($res['rows'] ?? []) as $row) {
        $impr = (float) ($row['impressions'] ?? 0);
        $ctr  = (float) ($row['ctr'] ?? 0);
        if ($impr >= $min_impr && $ctr <= $max_ctr) {
            $opps[] = [
                'page'        => $row['keys'][0] ?? '',
                'impressions' => (int) $impr,
                'clicks'      => (int) ($row['clicks'] ?? 0),
                'ctr'         => round($ctr * 100, 2),
                'position'    => round((float) ($row['position'] ?? 0), 1),
            ];
        }
    }
    usort($opps, static fn ($a, $b) => $b['impressions'] <=> $a['impressions']);
    return ['range' => [$start, $end], 'opportunities' => array_slice($opps, 0, 50), 'count' => count($opps), 'next' => 'Run seo-pro-optimize on each page with its query as the target keyword.'];
}
