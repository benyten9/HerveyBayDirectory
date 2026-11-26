<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The one road between NibWP and Bricksed.
 *
 * Every call goes through rest_do_request() rather than Bricksed_REST_API's
 * static methods. That is deliberate: the static methods skip the route's
 * permission_callback, which is where Bricksed checks the capability, the
 * nonce and the customer's own licence tier. Calling them directly would let
 * NibWP write things Bricksed itself would have refused.
 */

/**
 * Whether Bricksed is present on this site.
 */
function nibwp_bricksed_available(): bool
{
    return defined('BRICKSED_PLUGIN_FILE');
}

/**
 * Dispatch one request into Bricksed's REST namespace.
 *
 * @param string               $route  Path after the namespace, with its leading slash.
 * @param string               $method GET or POST.
 * @param array<string, mixed> $body   Body parameters (query parameters for a GET).
 * @return array<mixed>|WP_Error Bricksed's data on success, its own error on refusal.
 */
function nibwp_bricksed_request(string $route, string $method, array $body = [])
{
    if (!nibwp_bricksed_available()) {
        return new WP_Error(
            'bricksed_inactive',
            __('Bricksed is not active on this site. Install and activate it, then try again.', 'nibwp'),
            ['status' => 404]
        );
    }

    $request = new WP_REST_Request($method, '/bricksed/v1' . $route);
    if ($body !== []) {
        if ($method === 'GET') {
            $request->set_query_params($body);
        } else {
            $request->set_body_params($body);
        }
    }

    $response = rest_do_request($request);
    $status = $response->get_status();
    $data = $response->get_data();

    if ($status >= 400) {
        // Bricksed's refusal, carried through verbatim. Rewriting it here would
        // hide a licence or capability problem behind a generic failure, and
        // the person reading the agent's answer is the one who can fix it.
        $code = is_array($data) && isset($data['code']) ? (string) $data['code'] : 'bricksed_request_failed';
        $message = is_array($data) && isset($data['message'])
            ? (string) $data['message']
            : __('Bricksed refused the request.', 'nibwp');

        return new WP_Error($code, $message, ['status' => $status]);
    }

    return is_array($data) ? $data : [];
}
