<?php

namespace DirectoristPricingPlan\App\Enums\Plan;

defined( 'ABSPATH' ) || exit;

final class FeeType {
    public const FREE = 'free';
    public const PAID = 'paid';

    public static function all() {
        return [
            self::FREE,
            self::PAID,
        ];
    }
}