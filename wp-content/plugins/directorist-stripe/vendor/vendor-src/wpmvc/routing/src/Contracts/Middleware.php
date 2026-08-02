<?php

namespace DirectoristStripe\WpMVC\Routing\Contracts;

\defined('ABSPATH') || exit;
use WP_REST_Request;
use WP_Error;
interface Middleware
{
    /**
     * Handle an incoming request.
     *
     * @param WP_REST_Request  $wp_rest_request
     * @return bool|WP_Error
     */
    public function handle(WP_REST_Request $wp_rest_request);
}
