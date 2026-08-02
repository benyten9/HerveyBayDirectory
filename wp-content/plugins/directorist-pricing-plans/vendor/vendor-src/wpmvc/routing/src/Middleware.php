<?php

namespace DirectoristPricingPlan\WpMVC\Routing;

\defined('ABSPATH') || exit;
use DirectoristPricingPlan\WpMVC\Routing\Providers\RouteServiceProvider;
use DirectoristPricingPlan\WpMVC\Routing\Contracts\Middleware as MiddlewareContract;
use WP_Error;
class Middleware
{
    protected static array $middleware = [];
    public static function set_middleware_list(array $middleware)
    {
        static::$middleware = $middleware;
    }
    /**
     * @param array $middleware
     * @param bool $default_permission
     * @return bool|WP_Error
     */
    public static function is_user_allowed(array $middleware, bool $default_permission = \true)
    {
        $container = RouteServiceProvider::$container;
        foreach ($middleware as $middleware_name) {
            if (!\array_key_exists($middleware_name, static::$middleware)) {
                continue;
            }
            $current_middleware = static::$middleware[$middleware_name];
            $middleware_object = $container->get($current_middleware);
            if (!$middleware_object instanceof MiddlewareContract) {
                return \false;
            }
            $permission = $container->call([$middleware_object, 'handle']);
            if ($permission instanceof WP_Error || !$permission) {
                return $permission;
            }
        }
        return $default_permission;
    }
}
