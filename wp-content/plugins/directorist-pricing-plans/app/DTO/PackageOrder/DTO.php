<?php

namespace DirectoristPricingPlan\App\DTO\PackageOrder;

defined( "ABSPATH" ) || exit;

class DTO extends \DirectoristPricingPlan\WpMVC\DTO\DTO {
    private int $id;

    private int $order_id;

    private int $package_id;

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
     * Get the value of order_id
     *
     * @return int
     */
    public function get_order_id(): int {
        return $this->order_id;
    }

    /**
     * Set the value of order_id
     *
     * @param int $order_id 
     *
     * @return self
     */
    public function set_order_id( int $order_id ): self {
        $this->order_id = $order_id;

        return $this;
    }

    /**
     * Get the value of package_id
     *
     * @return int
     */
    public function get_package_id(): int {
        return $this->package_id;
    }

    /**
     * Set the value of package_id
     *
     * @param int $package_id 
     *
     * @return self
     */
    public function set_package_id( int $package_id ): self {
        $this->package_id = $package_id;

        return $this;
    }
}

