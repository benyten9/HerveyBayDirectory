<?php

namespace DirectoristPricingPlan\App\Enums\Plan;

defined( "ABSPATH" ) || exit;

final class Type {
    public const PACKAGE         = 'package';
    public const PAY_PER_LISTING = 'pay_per_listing';

    public static function all() {
        return [ 'package', 'pay_per_listing' ];
    }
}
