<?php

namespace DirectoristPricingPlan\App\Enums\Migration;

defined( 'ABSPATH' ) || exit;

final class Status {
    const PENDING     = 'pending';
    const IN_PROGRESS = 'in_progress';
    const COMPLETED   = 'completed';
    const FAILED      = 'failed';

    public static function all() {
        return [
            self::PENDING,
            self::IN_PROGRESS,
            self::COMPLETED,
            self::FAILED,
        ];
    }
}
