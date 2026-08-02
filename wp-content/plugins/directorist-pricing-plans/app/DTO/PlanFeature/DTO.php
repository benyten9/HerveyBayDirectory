<?php

namespace DirectoristPricingPlan\App\DTO\PlanFeature;

defined( "ABSPATH" ) || exit;

class DTO extends \DirectoristPricingPlan\WpMVC\DTO\DTO {
    private int $id;

    private int $plan_id;

    private string $feature_key;

    private bool $is_enabled;

    private bool $is_show_in_pricing_table;
    
    private array $data;

    private int $sort_order;

    /**
     * Get the value of id
     *
     * @return int
     */
    public function get_id(): int {
        return $this->id;
    }

    /**
     * Set the value of id
     *
     * @param int $id 
     *
     * @return self
     */
    public function set_id( int $id ): self {
        $this->id = $id;

        return $this;
    }

    /**
     * Get the value of plan_id
     *
     * @return int
     */
    public function get_plan_id(): int {
        return $this->plan_id;
    }

    /**
     * Set the value of plan_id
     *
     * @param int $plan_id 
     *
     * @return self
     */
    public function set_plan_id( int $plan_id ): self {
        $this->plan_id = $plan_id;

        return $this;
    }

    /**
     * Get the value of feature_key
     *
     * @return string
     */
    public function get_feature_key(): string {
        return $this->feature_key;
    }

    /**
     * Set the value of feature_key
     *
     * @param string $feature_key 
     *
     * @return self
     */
    public function set_feature_key( string $feature_key ): self {
        $this->feature_key = $feature_key;

        return $this;
    }

    /**
     * Get the value of is_enabled
     *
     * @return bool
     */
    public function is_is_enabled(): bool {
        return $this->is_enabled;
    }

    /**
     * Set the value of is_enabled
     *
     * @param bool $is_enabled 
     *
     * @return self
     */
    public function set_is_enabled( bool $is_enabled ): self {
        $this->is_enabled = $is_enabled;

        return $this;
    }

    /**
     * Get the value of is_show_in_pricing_table
     *
     * @return bool
     */
    public function is_is_show_in_pricing_table(): bool {
        return $this->is_show_in_pricing_table;
    }

    /**
     * Set the value of is_show_in_pricing_table
     *
     * @param bool $is_show_in_pricing_table 
     *
     * @return self
     */
    public function set_is_show_in_pricing_table( bool $is_show_in_pricing_table ): self {
        $this->is_show_in_pricing_table = $is_show_in_pricing_table;

        return $this;
    }

    /**
     * Get the value of data
     *
     * @return array
     */
    public function get_data(): array {
        return $this->data;
    }

    /**
     * Set the value of data
     *
     * @param array $data 
     *
     * @return self
     */
    public function set_data( array $data ): self {
        $this->data = $data;

        return $this;
    }

    /**
     * Get the value of sort_order
     *
     * @return int
     */
    public function get_sort_order(): int {
        return $this->sort_order;
    }

    /**
     * Set the value of sort_order
     *
     * @param int $sort_order 
     *
     * @return self
     */
    public function set_sort_order( int $sort_order ): self {
        $this->sort_order = $sort_order;

        return $this;
    }
}