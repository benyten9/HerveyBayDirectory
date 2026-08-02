<?php

namespace DirectoristPricingPlan\App\Enums\Queue;

defined( 'ABSPATH' ) || exit;

final class Status {
    const IN_QUEUE    = 'in_queue';
    const IN_PROGRESS = 'in_progress';
    const COMPLETED   = 'completed';
    const SKIPPED     = 'skipped';
    const FAILED      = 'failed';

    public static function all() {
        return [
            self::IN_QUEUE,
            self::IN_PROGRESS,
            self::COMPLETED,
            self::SKIPPED,
            self::FAILED,
        ];
    }
}
