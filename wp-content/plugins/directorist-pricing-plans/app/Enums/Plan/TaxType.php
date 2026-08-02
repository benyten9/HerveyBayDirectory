<?php

namespace DirectoristPricingPlan\App\Enums\Plan;

defined( 'ABSPATH' ) || exit;

final class TaxType {
    public const FLAT    = 'flat';
    public const PERCENT = 'percent';

    public static function all() {
        return [
            self::FLAT,
            self::PERCENT,
        ];
    }
}