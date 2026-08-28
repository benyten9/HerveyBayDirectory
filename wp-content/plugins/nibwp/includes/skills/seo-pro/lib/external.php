<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * SEO Pro — external data credentials + HTTP helpers.
 *
 * Credentials live in dedicated options and are NEVER required: an external
 * ability returns a clear "configure credentials" error when its key is missing,
 * so the skill degrades gracefully on installs that haven't connected anything.
 *
 * Options:
 *   nibwp_seo_pro_indexnow  => string key
 *   nibwp_seo_pro_gsc       => ['access_token'=>..., 'site_url'=>...]
 *   nibwp_seo_pro_serp      => ['provider'=>'serpapi', 'key'=>...]
 */

/** @return mixed */
function nibwp_seo_pro_cred(string $name)
{
    return get_option('nibwp_seo_pro_' . $name, '');
}

function nibwp_seo_pro_set_cred(string $name, $value): void
{
    update_option('nibwp_seo_pro_' . $name, $value, false);
}

/** Standard "not configured" WP_Error for an external ability. */
function nibwp_seo_pro_no_cred(string $what, string $how): WP_Error
{
    return new WP_Error('not_configured', sprintf('%s is not configured. %s', $what, $how), ['status' => 412]);
}

/**
 * JSON HTTP helper. Returns the decoded body array or a WP_Error.
 *
 * @param array<string,mixed> $args
 * @return array<mixed>|WP_Error
 */
function nibwp_seo_pro_http(string $url, array $args = [])
{
    $args = wp_parse_args($args, ['timeout' => 20, 'method' => 'GET']);
    $resp = wp_remote_request($url, $args);
    if (is_wp_error($resp)) {
        return $resp;
    }
    $code = (int) wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    $data = json_decode($body, true);
    if ($code >= 400) {
        return new WP_Error('http_error', sprintf('Request failed (%d).', $code), ['status' => $code, 'body' => is_array($data) ? $data : substr((string) $body, 0, 500)]);
    }
    return is_array($data) ? $data : ['raw' => $body];
}
