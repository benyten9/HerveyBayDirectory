<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The one road between NibWP and Etchedy.
 *
 * Every call goes through rest_do_request() rather than Etchedy_REST_API's
 * static methods. That is deliberate: the static methods skip the route's
 * permission_callback, which is where Etchedy checks the capability, the
 * nonce and the customer's own licence tier. Calling them directly would let
 * NibWP write things Etchedy itself would have refused, which is not a
 * shortcut — it is a privilege escalation wearing one.
 */

/**
 * Whether Etchedy is present on this site.
 */
function nibwp_etchedy_available(): bool
{
    return defined('ETCHEDY_PLUGIN_FILE');
}

/**
 * Dispatch one request into Etchedy's REST namespace.
 *
 * @param string               $route  Path after the namespace, with its leading slash.
 * @param string               $method GET or POST.
 * @param array<string, mixed> $body   Body parameters.
 * @return array<mixed>|WP_Error Etchedy's data on success, its own error on refusal.
 */
function nibwp_etchedy_request(string $route, string $method, array $body = [])
{
    if (!nibwp_etchedy_available()) {
        return new WP_Error(
            'etchedy_inactive',
            __('Etchedy is not active on this site. Install and activate it, then try again.', 'nibwp'),
            ['status' => 404]
        );
    }

    $request = new WP_REST_Request($method, '/etchedy/v1' . $route);
    if ($body !== []) {
        $request->set_body_params($body);
    }

    $response = rest_do_request($request);
    $status = $response->get_status();
    $data = $response->get_data();

    if ($status >= 400) {
        // Etchedy's refusal, carried through verbatim. Rewriting it here would
        // hide a licence or capability problem behind a generic failure, and
        // the person reading the agent's answer is the one who can fix it.
        $code = is_array($data) && isset($data['code']) ? (string) $data['code'] : 'etchedy_request_failed';
        $message = is_array($data) && isset($data['message'])
            ? (string) $data['message']
            : __('Etchedy refused the request.', 'nibwp');

        return new WP_Error($code, $message, ['status' => $status]);
    }

    return is_array($data) ? $data : [];
}
