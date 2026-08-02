<?php

namespace DirectoristPricingPlan\App\DTO\PlanOrderMeta;

defined( "ABSPATH" ) || exit;

use Directorist\Helpers\DateTime;

class DTO extends \DirectoristPricingPlan\WpMVC\DTO\DTO {
    private int $id;

    private int $order_id;

    private bool $is_recurring = false;

    private bool $is_trial = false;

    private ?string $interval_type = null;

    private ?int $interval_count = null;

    private ?DateTime $current_period_end = null;

    public function get_id(): int {
        return $this->id;
    }

    public function set_id( int $id ): self {
        $this->id = $id;

        return $this;
    }

    public function get_order_id(): int {
        return $this->order_id;
    }

    public function set_order_id( int $order_id ): self {
        $this->order_id = $order_id;

        return $this;
    }

    public function is_is_recurring(): bool {
        return $this->is_recurring;
    }

    public function set_is_recurring( bool $is_recurring ): self {
        $this->is_recurring = $is_recurring;

        return $this;
    }

    public function is_is_trial(): bool {
        return $this->is_trial;
    }

    public function set_is_trial( bool $is_trial ): self {
        $this->is_trial = $is_trial;

        return $this;
    }

    public function get_interval_type(): ?string {
        return $this->interval_type;
    }

    public function set_interval_type( ?string $interval_type ): self {
        $this->interval_type = $interval_type;

        return $this;
    }

    public function get_interval_count(): ?int {
        return $this->interval_count;
    }

    public function set_interval_count( ?int $interval_count ): self {
        $this->interval_count = $interval_count;

        return $this;
    }

    public function get_current_period_end(): ?DateTime {
        return $this->current_period_end;
    }

    public function set_current_period_end( ?DateTime $current_period_end ): self {
        $this->current_period_end = $current_period_end;

        return $this;
    }
}
