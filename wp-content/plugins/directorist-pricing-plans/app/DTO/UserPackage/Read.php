<?php

namespace DirectoristPricingPlan\App\DTO\UserPackage;

defined( "ABSPATH" ) || exit;

use DirectoristPricingPlan\WpMVC\DTO\DTO;

class Read extends DTO {
    private int $page;

    private int $per_page;
    
    private ?string $search = null;

    private ?int $user_id = null;

    private ?int $directory_type_id = null;

    private ?bool $is_recurring = null;

    private ?bool $with_usage_data = null;

    /**
     * Get the value of page
     *
     * @return int
     */
    public function get_page(): int {
        return $this->page;
    }

    /**
     * Set the value of page
     *
     * @param int $page 
     *
     * @return self
     */
    public function set_page( int $page ): self {
        $this->page = $page;

        return $this;
    }

    /**
     * Get the value of per_page
     *
     * @return int
     */
    public function get_per_page(): int {
        return $this->per_page;
    }

    /**
     * Set the value of per_page
     *
     * @param int $per_page 
     *
     * @return self
     */
    public function set_per_page( int $per_page ): self {
        $this->per_page = $per_page;

        return $this;
    }

    /**
     * Get the value of search
     *
     * @return ?string
     */
    public function get_search(): ?string {
        return $this->search;
    }

    /**
     * Set the value of search
     *
     * @param ?string $search 
     *
     * @return self
     */
    public function set_search( ?string $search ): self {
        $this->search = $search;

        return $this;
    }

    /**
     * Get the value of user_id
     *
     * @return ?int
     */
    public function get_user_id(): ?int {
        return $this->user_id;
    }

    /**
     * Set the value of user_id
     *
     * @param ?int $user_id 
     *
     * @return self
     */
    public function set_user_id( ?int $user_id ): self {
        $this->user_id = $user_id;

        return $this;
    }

    /**
     * Get the value of directory_type_id
     *
     * @return ?int
     */
    public function get_directory_type_id(): ?int {
        return $this->directory_type_id;
    }

    /**
     * Set the value of directory_type_id
     *
     * @param ?int $directory_type_id 
     *
     * @return self
     */
    public function set_directory_type_id( ?int $directory_type_id ): self {
        $this->directory_type_id = $directory_type_id;

        return $this;
    }

    /**
     * Get the value of is_recurring
     *
     * @return ?bool
     */
    public function is_is_recurring(): ?bool {
        return $this->is_recurring;
    }

    /**
     * Set the value of is_recurring
     *
     * @param ?bool $is_recurring 
     *
     * @return self
     */
    public function set_is_recurring( ?bool $is_recurring ): self {
        $this->is_recurring = $is_recurring;

        return $this;
    }

    /**
     * Get the value of with_usage_data
     *
     * @return ?bool
     */
    public function is_with_usage_data(): ?bool {
        return $this->with_usage_data;
    }
    
    /**
     * Set the value of with_usage_data
     *
     * @param ?bool $with_usage_data 
     *
     * @return self
     */
    public function set_with_usage_data( ?bool $with_usage_data ): self {
        $this->with_usage_data = $with_usage_data;

        return $this;
    }
}

