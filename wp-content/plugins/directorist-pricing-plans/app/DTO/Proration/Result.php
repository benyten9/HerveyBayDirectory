<?php

namespace DirectoristPricingPlan\App\DTO\Proration;

defined( 'ABSPATH' ) || exit;

use Directorist\Helpers\DateTime;

class Result {
    private bool $is_allowed;

    private ?string $error_message;

    private float $adjusted_price;

    private float $credit_amount;

    private ?DateTime $override_period_end;

    private ?int $extending_days;

    private function __construct(
        bool $is_allowed,
        ?string $error_message,
        float $adjusted_price,
        float $credit_amount,
        ?DateTime $override_period_end,
        ?int $extending_days
    ) {
        $this->is_allowed          = $is_allowed;
        $this->error_message       = $error_message;
        $this->adjusted_price      = $adjusted_price;
        $this->credit_amount       = $credit_amount;
        $this->override_period_end = $override_period_end;
        $this->extending_days      = $extending_days;
    }

    public static function allow( float $adjusted_price, ?DateTime $override_period_end, float $credit_amount, ?int $extending_days = null ): self {
        return new self( true, null, $adjusted_price, $credit_amount, $override_period_end, $extending_days );
    }

    public static function deny( string $error_message ): self {
        return new self( false, $error_message, 0.0, 0.0, null, null );
    }

    public function is_allowed(): bool {
        return $this->is_allowed;
    }

    public function get_error_message(): ?string {
        return $this->error_message;
    }

    public function get_adjusted_price(): float {
        return $this->adjusted_price;
    }

    public function get_credit_amount(): float {
        return $this->credit_amount;
    }

    public function get_override_period_end(): ?DateTime {
        return $this->override_period_end;
    }

    public function get_extending_days(): ?int {
        return $this->extending_days;
    }
}
