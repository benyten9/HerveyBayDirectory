<?php

namespace DirectoristPricingPlan\App\Enums\Plan;

defined( 'ABSPATH' ) || exit;

final class Status {
    public const ACTIVE   = 'active';
    public const INACTIVE = 'inactive';

    public static function all() {
        return [
            self::ACTIVE,
            self::INACTIVE,
        ];
    }
}