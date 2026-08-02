<?php

namespace DirectoristPricingPlan\App\DTO\UserPackage;

defined( "ABSPATH" ) || exit;

use Directorist\Helpers\DateTime;

class DTO extends \DirectoristPricingPlan\WpMVC\DTO\DTO {
    private int $id;

    private int $user_id;

    private int $directory_type_id;

    private int $plan_id;

    private int $listing_display_priority = 0;

    private int $last_order_id;

    private bool $is_recurring = false;

    private bool $is_trial = false;

    private bool $is_legacy = false;

    private string $status;

    private ?string $subscription_id;

    private ?string $subscription_method;

    private ?string $subscription_currency;

    private ?float $subscription_amount;

    private ?DateTime $started_at = null;

    private ?DateTime $current_period_end = null;

    private ?DateTime $cancelled_at = null;

    private ?DateTime $created_at = null;

    private ?DateTime $updated_at = null;

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
     * Get the value of user_id
     *
     * @return int
     */
    public function get_user_id(): int {
        return $this->user_id;
    }

    /**
     * Set the value of user_id
     *
     * @param int $user_id 
     *
     * @return self
     */
    public function set_user_id( int $user_id ): self {
        $this->user_id = $user_id;

        return $this;
    }

    /**
     * Get the value of directory_type_id
     *
     * @return int
     */
    public function get_directory_type_id(): int {
        return $this->directory_type_id;
    }

    /**
     * Set the value of directory_type_id
     *
     * @param int $directory_type_id 
     *
     * @return self
     */
    public function set_directory_type_id( int $directory_type_id ): self {
        $this->directory_type_id = $directory_type_id;

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
     * Get the value of listing_display_priority
     *
     * @return int
     */
    public function get_listing_display_priority(): int {
        return $this->listing_display_priority;
    }
    
    /**
     * Set the value of listing_display_priority
     *
     * @param int $listing_display_priority 
     *
     * @return self
     */
    public function set_listing_display_priority( int $listing_display_priority ): self {
        $this->listing_display_priority = $listing_display_priority;

        return $this;
    }

    /**
     * Get the value of last_order_id
     *
     * @return int
     */
    public function get_last_order_id(): int {
        return $this->last_order_id;
    }

    /**
     * Set the value of last_order_id
     *
     * @param int $last_order_id 
     *
     * @return self
     */
    public function set_last_order_id( int $last_order_id ): self {
        $this->last_order_id = $last_order_id;

        return $this;
    }

    /**
     * Get the value of is_recurring
     *
     * @return bool
     */
    public function is_is_recurring(): bool {
        return $this->is_recurring;
    }

    /**
     * Set the value of is_recurring
     *
     * @param bool $is_recurring 
     *
     * @return self
     */
    public function set_is_recurring( bool $is_recurring ): self {
        $this->is_recurring = $is_recurring;

        return $this;
    }

    /**
     * Get the value of is_trial
     *
     * @return bool
     */
    public function is_is_trial(): bool {
        return $this->is_trial;
    }

    /**
     * Set the value of is_trial
     *
     * @param bool $is_trial 
     *
     * @return self
     */
    public function set_is_trial( bool $is_trial ): self {
        $this->is_trial = $is_trial;

        return $this;
    }

    public function is_is_legacy(): bool {
        return $this->is_legacy;
    }

    public function set_is_legacy( bool $is_legacy ): self {
        $this->is_legacy = $is_legacy;

        return $this;
    }

    /**
     * Get the value of status
     *
     * @return string
     */
    public function get_status(): string {
        return $this->status;
    }

    /**
     * Set the value of status
     *
     * @param string $status 
     *
     * @return self
     */
    public function set_status( string $status ): self {
        $this->status = $status;

        return $this;
    }

    /**
     * Get the value of subscription_id
     *
     * @return ?string
     */
    public function get_subscription_id(): ?string {
        return $this->subscription_id;
    }

    /**
     * Set the value of subscription_id
     *
     * @param ?string $subscription_id 
     *
     * @return self
     */
    public function set_subscription_id( ?string $subscription_id ): self {
        $this->subscription_id = $subscription_id;

        return $this;
    }

    /**
     * Get the value of subscription_method
     *
     * @return ?string
     */
    public function get_subscription_method(): ?string {
        return $this->subscription_method;
    }

    /**
     * Set the value of subscription_method
     *
     * @param ?string $subscription_method 
     *
     * @return self
     */
    public function set_subscription_method( ?string $subscription_method ): self {
        $this->subscription_method = $subscription_method;

        return $this;
    }

    /**
     * Get the value of subscription_currency
     *
     * @return ?string
     */
    public function get_subscription_currency(): ?string {
        return $this->subscription_currency;
    }

    /**
     * Set the value of subscription_currency
     *
     * @param ?string $subscription_currency 
     *
     * @return self
     */
    public function set_subscription_currency( ?string $subscription_currency ): self {
        $this->subscription_currency = $subscription_currency;

        return $this;
    }

    /**
     * Get the value of subscription_amount
     *
     * @return ?float
     */
    public function get_subscription_amount(): ?float {
        return $this->subscription_amount;
    }

    /**
     * Set the value of subscription_amount
     *
     * @param ?float $subscription_amount 
     *
     * @return self
     */
    public function set_subscription_amount( ?float $subscription_amount ): self {
        $this->subscription_amount = $subscription_amount;

        return $this;
    }

    /**
     * Get the value of started_at
     *
     * @return ?DateTime
     */
    public function get_started_at(): ?DateTime {
        return $this->started_at;
    }

    /**
     * Set the value of started_at
     *
     * @param ?DateTime $started_at 
     *
     * @return self
     */
    public function set_started_at( ?DateTime $started_at ): self {
        $this->started_at = $started_at;

        return $this;
    }

    /**
     * Get the value of current_period_end
     *
     * @return ?DateTime
     */
    public function get_current_period_end(): ?DateTime {
        return $this->current_period_end;
    }

    /**
     * Set the value of current_period_end
     *
     * @param ?DateTime $current_period_end 
     *
     * @return self
     */
    public function set_current_period_end( ?DateTime $current_period_end ): self {
        $this->current_period_end = $current_period_end;

        return $this;
    }

    /**
     * Get the value of cancelled_at
     *
     * @return ?DateTime
     */
    public function get_cancelled_at(): ?DateTime {
        return $this->cancelled_at;
    }

    /**
     * Set the value of cancelled_at
     *
     * @param ?DateTime $cancelled_at 
     *
     * @return self
     */
    public function set_cancelled_at( ?DateTime $cancelled_at ): self {
        $this->cancelled_at = $cancelled_at;

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

    /**
     * Get the value of updated_at
     *
     * @return ?DateTime
     */
    public function get_updated_at(): ?DateTime {
        return $this->updated_at;
    }

    /**
     * Set the value of updated_at
     *
     * @param ?DateTime $updated_at 
     *
     * @return self
     */
    public function set_updated_at( ?DateTime $updated_at ): self {
        $this->updated_at = $updated_at;

        return $this;
    }
}

