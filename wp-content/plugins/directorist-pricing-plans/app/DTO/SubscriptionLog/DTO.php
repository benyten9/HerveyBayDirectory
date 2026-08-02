<?php

namespace DirectoristPricingPlan\App\DTO\SubscriptionLog;

defined( "ABSPATH" ) || exit;

use Directorist\Helpers\DateTime;

class DTO extends \DirectoristPricingPlan\WpMVC\DTO\DTO {
    private int $id;

    private int $package_id;

    private string $action;

    private string $triggered_by;

    private ?string $description = null;

    private ?DateTime $created_at = null;

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

    /**
     * Get the value of action
     *
     * @return string
     */
    public function get_action(): string {
        return $this->action;
    }

    /**
     * Set the value of action
     *
     * @param string $action 
     *
     * @return self
     */
    public function set_action( string $action ): self {
        $this->action = $action;

        return $this;
    }

    /**
     * Get the value of triggered_by
     *
     * @return string
     */
    public function get_triggered_by(): string {
        return $this->triggered_by;
    }

    /**
     * Set the value of triggered_by
     *
     * @param string $triggered_by
     *
     * @return self
     */
    public function set_triggered_by( string $triggered_by ): self {
        $this->triggered_by = $triggered_by;

        return $this;
    }

    /**
     * Get the value of description
     *
     * @return ?string
     */
    public function get_description(): ?string {
        return $this->description;
    }

    /**
     * Set the value of description
     *
     * @param ?string $description 
     *
     * @return self
     */
    public function set_description( ?string $description ): self {
        $this->description = $description;

        return $this;
    }

    /**
     * Get the value of created_at
     *
     * @return ?DateTime
     */
    public function get_created_at(): ?DateTime {
        return $this->created_at;
    }

    /**
     * Set the value of created_at
     *
     * @param ?DateTime $created_at 
     *
     * @return self
     */
    public function set_created_at( ?DateTime $created_at ): self {
        $this->created_at = $created_at;

        return $this;
    }
}

