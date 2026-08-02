<?php

namespace DirectoristPricingPlan\App\Enums\Order;

defined( 'ABSPATH' ) || exit;

final class RefType {
    public const PRICING_PLAN = 'pricing_plan';

    public static function all() {
        return [
            self::PRICING_PLAN,
        ];
    }
}