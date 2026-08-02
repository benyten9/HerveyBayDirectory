<?php

namespace DirectoristPricingPlan\App\DTO\Plan;

defined( "ABSPATH" ) || exit;

class DTO extends \DirectoristPricingPlan\WpMVC\DTO\DTO
{
    private int $id;

    private string $type;

    private string $title;

    private ?string $description;

    private int $directory_type_id;

    private int $allowed_listings;

    private bool $is_allowed_unlimited_listings;

    private int $allowed_featured_listings;

    private bool $is_allowed_unlimited_featured_listings;

    private string $fee_type;

    private float $price;

    private bool $is_taxable;

    private string $tax_type;

    private float $tax_rate;

    private string $interval_type;

    private int $interval_count;

    private bool $is_subscription_enabled;

    private bool $is_trial_enabled;

    private string $trial_interval_type;

    private int $trial_interval_count;

    private int $sort_order;

    private bool $is_published;

    private bool $is_hidden_from_plans_list;

    private bool $is_marked_as_recommended;

    private bool $is_fallback;

    private int $fallback_plan_id;

    private int $listing_display_priority;

    private bool $is_featured;

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
     * Get the value of type
     *
     * @return string
     */
    public function get_type(): string {
        return $this->type;
    }

    /**
     * Set the value of type
     *
     * @param string $type
     *
     * @return self
     */
    public function set_type( string $type ): self {
        $this->type = $type;

        return $this;
    }

    /**
     * Get the value of title
     *
     * @return string
     */
    public function get_title(): string {
        return $this->title;
    }

    /**
     * Set the value of title
     *
     * @param string $title 
     *
     * @return self
     */
    public function set_title( string $title ): self {
        $this->title = $title;

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
     * Get the value of allowed_listings
     *
     * @return int
     */
    public function get_allowed_listings(): int {
        return $this->allowed_listings;
    }

    /**
     * Set the value of allowed_listings
     *
     * @param int $allowed_listings 
     *
     * @return self
     */
    public function set_allowed_listings( int $allowed_listings ): self {
        $this->allowed_listings = $allowed_listings;

        return $this;
    }

    /**
     * Get the value of is_allowed_unlimited_listings
     *
     * @return bool
     */
    public function is_is_allowed_unlimited_listings(): bool {
        return $this->is_allowed_unlimited_listings;
    }

    /**
     * Set the value of is_allowed_unlimited_listings
     *
     * @param bool $is_allowed_unlimited_listings 
     *
     * @return self
     */
    public function set_is_allowed_unlimited_listings( bool $is_allowed_unlimited_listings ): self {
        $this->is_allowed_unlimited_listings = $is_allowed_unlimited_listings;

        return $this;
    }

    /**
     * Get the value of allowed_featured_listings
     *
     * @return int
     */
    public function get_allowed_featured_listings(): int {
        return $this->allowed_featured_listings;
    }

    /**
     * Set the value of allowed_featured_listings
     *
     * @param int $allowed_featured_listings 
     *
     * @return self
     */
    public function set_allowed_featured_listings( int $allowed_featured_listings ): self {
        $this->allowed_featured_listings = $allowed_featured_listings;

        return $this;
    }

    /**
     * Get the value of is_allowed_unlimited_featured_listings
     *
     * @return bool
     */
    public function is_is_allowed_unlimited_featured_listings(): bool {
        return $this->is_allowed_unlimited_featured_listings;
    }

    /**
     * Set the value of is_allowed_unlimited_featured_listings
     *
     * @param bool $is_allowed_unlimited_featured_listings 
     *
     * @return self
     */
    public function set_is_allowed_unlimited_featured_listings( bool $is_allowed_unlimited_featured_listings ): self {
        $this->is_allowed_unlimited_featured_listings = $is_allowed_unlimited_featured_listings;

        return $this;
    }

    /**
     * Get the value of fee_type
     *
     * @return string
     */
    public function get_fee_type(): string {
        return $this->fee_type;
    }

    /**
     * Set the value of fee_type
     *
     * @param string $fee_type 
     *
     * @return self
     */
    public function set_fee_type( string $fee_type ): self {
        $this->fee_type = $fee_type;

        return $this;
    }

    /**
     * Get the value of price
     *
     * @return float
     */
    public function get_price(): float {
        return $this->price;
    }

    /**
     * Set the value of price
     *
     * @param float $price 
     *
     * @return self
     */
    public function set_price( float $price ): self {
        $this->price = $price;

        return $this;
    }

    /**
     * Get the value of is_taxable
     *
     * @return bool
     */
    public function is_is_taxable(): bool {
        return $this->is_taxable;
    }

    /**
     * Set the value of is_taxable
     *
     * @param bool $is_taxable 
     *
     * @return self
     */
    public function set_is_taxable( bool $is_taxable ): self {
        $this->is_taxable = $is_taxable;

        return $this;
    }

    /**
     * Get the value of tax_type
     *
     * @return string
     */
    public function get_tax_type(): string {
        return $this->tax_type;
    }

    /**
     * Set the value of tax_type
     *
     * @param string $tax_type 
     *
     * @return self
     */
    public function set_tax_type( string $tax_type ): self {
        $this->tax_type = $tax_type;

        return $this;
    }

    /**
     * Get the value of tax_rate
     *
     * @return float
     */
    public function get_tax_rate(): float {
        return $this->tax_rate;
    }

    /**
     * Set the value of tax_rate
     *
     * @param float $tax_rate 
     *
     * @return self
     */
    public function set_tax_rate( float $tax_rate ): self {
        $this->tax_rate = $tax_rate;

        return $this;
    }

    /**
     * Get the value of interval_type
     *
     * @return string
     */
    public function get_interval_type(): string {
        return $this->interval_type;
    }

    /**
     * Set the value of interval_type
     *
     * @param string $interval_type 
     *
     * @return self
     */
    public function set_interval_type( string $interval_type ): self {
        $this->interval_type = $interval_type;

        return $this;
    }

    /**
     * Get the value of interval_count
     *
     * @return int
     */
    public function get_interval_count(): int {
        return $this->interval_count;
    }

    /**
     * Set the value of interval_count
     *
     * @param int $interval_count 
     *
     * @return self
     */
    public function set_interval_count( int $interval_count ): self {
        $this->interval_count = $interval_count;

        return $this;
    }

    /**
     * Get the value of is_subscription_enabled
     *
     * @return bool
     */
    public function is_is_subscription_enabled(): bool {
        return $this->is_subscription_enabled;
    }

    /**
     * Set the value of is_subscription_enabled
     *
     * @param bool $is_subscription_enabled 
     *
     * @return self
     */
    public function set_is_subscription_enabled( bool $is_subscription_enabled ): self {
        $this->is_subscription_enabled = $is_subscription_enabled;

        return $this;
    }

    /**
     * Get the value of is_trial_enabled
     *
     * @return bool
     */
    public function is_is_trial_enabled(): bool {
        return $this->is_trial_enabled;
    }

    /**
     * Set the value of is_trial_enabled
     *
     * @param bool $is_trial_enabled 
     *
     * @return self
     */
    public function set_is_trial_enabled( bool $is_trial_enabled ): self {
        $this->is_trial_enabled = $is_trial_enabled;

        return $this;
    }

    /**
     * Get the value of trial_interval_type
     *
     * @return string
     */
    public function get_trial_interval_type(): string {
        return $this->trial_interval_type;
    }

    /**
     * Set the value of trial_interval_type
     *
     * @param string $trial_interval_type 
     *
     * @return self
     */
    public function set_trial_interval_type( string $trial_interval_type ): self {
        $this->trial_interval_type = $trial_interval_type;

        return $this;
    }

    /**
     * Get the value of trial_interval_count
     *
     * @return int
     */
    public function get_trial_interval_count(): int {
        return $this->trial_interval_count;
    }

    /**
     * Set the value of trial_interval_count
     *
     * @param int $trial_interval_count 
     *
     * @return self
     */
    public function set_trial_interval_count( int $trial_interval_count ): self {
        $this->trial_interval_count = $trial_interval_count;

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

    /**
     * Get the value of is_published
     *
     * @return bool
     */
    public function is_is_published(): bool {
        return $this->is_published;
    }

    /**
     * Set the value of is_published
     *
     * @param bool $is_published 
     *
     * @return self
     */
    public function set_is_published( bool $is_published ): self {
        $this->is_published = $is_published;

        return $this;
    }

    /**
     * Get the value of is_hidden_from_plans_list
     *
     * @return bool
     */
    public function is_is_hidden_from_plans_list(): bool {
        return $this->is_hidden_from_plans_list;
    }

    /**
     * Set the value of is_hidden_from_plans_list
     *
     * @param bool $is_hidden_from_plans_list 
     *
     * @return self
     */
    public function set_is_hidden_from_plans_list( bool $is_hidden_from_plans_list ): self {
        $this->is_hidden_from_plans_list = $is_hidden_from_plans_list;

        return $this;
    }

    /**
     * Get the value of is_marked_as_recommended
     *
     * @return bool
     */
    public function is_is_marked_as_recommended(): bool {
        return $this->is_marked_as_recommended;
    }

    /**
     * Set the value of is_marked_as_recommended
     *
     * @param bool $is_marked_as_recommended 
     *
     * @return self
     */
    public function set_is_marked_as_recommended( bool $is_marked_as_recommended ): self {
        $this->is_marked_as_recommended = $is_marked_as_recommended;

        return $this;
    }

    /**
     * Get the value of is_fallback
     *
     * @return bool
     */
    public function is_is_fallback(): bool {
        return $this->is_fallback;
    }

    /**
     * Set the value of is_fallback
     *
     * @param bool $is_fallback 
     *
     * @return self
     */
    public function set_is_fallback( bool $is_fallback ): self {
        $this->is_fallback = $is_fallback;

        return $this;
    }

    /**
     * Get the value of fallback_plan_id
     *
     * @return int
     */
    public function get_fallback_plan_id(): int {
        return $this->fallback_plan_id;
    }

    /**
     * Set the value of fallback_plan_id
     *
     * @param int $fallback_plan_id 
     *
     * @return self
     */
    public function set_fallback_plan_id( int $fallback_plan_id ): self {
        $this->fallback_plan_id = $fallback_plan_id;

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
     * Get the value of is_featured
     *
     * @return bool
     */
    public function is_is_featured(): bool {
        return $this->is_featured;
    }

    /**
     * Set the value of is_featured
     *
     * @param bool $is_featured
     *
     * @return self
     */
    public function set_is_featured( bool $is_featured ): self {
        $this->is_featured = $is_featured;

        return $this;
    }
}
