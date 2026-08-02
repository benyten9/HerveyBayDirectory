<?php

namespace DirectoristPricingPlan\App\Enums\Plan;

defined( 'ABSPATH' ) || exit;

final class TrialInterval {
    public const DAY   = 'day';
    public const WEEK  = 'week';
    public const MONTH = 'month';
    public const YEAR  = 'year';

    public static function all() {
        return [
            self::DAY,
            self::WEEK,
            self::MONTH,
            self::YEAR,
        ];
    }
}