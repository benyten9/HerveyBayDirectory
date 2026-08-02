<?php

namespace DirectoristPricingPlan\App\Enums\UserPackage;

defined( 'ABSPATH' ) || exit;

final class Status {
    public const ACTIVE                  = 'active';
    public const CANCELLED               = 'cancelled';
    public const CANCELLED_AT_PERIOD_END = 'canceled_at_period_end';
    public const EXPIRED                 = 'expired';
    public const ARCHIVED                = 'archived';

    public static function all() {
        return [
            self::ACTIVE,
            self::CANCELLED,
            self::CANCELLED_AT_PERIOD_END,
            self::EXPIRED,
            self::ARCHIVED,
        ];
    }
}
