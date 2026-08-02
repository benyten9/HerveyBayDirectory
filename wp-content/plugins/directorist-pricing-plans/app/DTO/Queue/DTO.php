<?php

namespace DirectoristPricingPlan\App\DTO\Queue;

defined( "ABSPATH" ) || exit;

class DTO extends \DirectoristPricingPlan\WpMVC\DTO\DTO {
    private int $id;

    private ?int $task_id;

    private string $task_type;

    private ?array $task_data;

    private ?string $message;

    private string $status;

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
     * Get the value of task_id
     *
     * @return ?int
     */
    public function get_task_id(): ?int {
        return $this->task_id;
    }

    /**
     * Set the value of task_id
     *
     * @param ?int $task_id 
     *
     * @return self
     */
    public function set_task_id( ?int $task_id ): self {
        $this->task_id = $task_id;

        return $this;
    }

    /**
     * Get the value of task_type
     *
     * @return string
     */
    public function get_task_type(): string {
        return $this->task_type;
    }

    /**
     * Set the value of task_type
     *
     * @param string $task_type 
     *
     * @return self
     */
    public function set_task_type( string $task_type ): self {
        $this->task_type = $task_type;

        return $this;
    }

    /**
     * Get the value of task_data
     *
     * @return ?string
     */
    public function get_task_data(): ?array {
        return $this->task_data;
    }

    /**
     * Set the value of task_data
     *
     * @param ?array $task_data 
     *
     * @return self
     */
    public function set_task_data( ?array $task_data ): self {
        $this->task_data = $task_data;

        return $this;
    }

    /**
     * Get the value of message
     *
     * @return ?string
     */
    public function get_message(): ?string {
        return $this->message;
    }

    /**
     * Set the value of message
     *
     * @param ?string $message 
     *
     * @return self
     */
    public function set_message( ?string $message ): self {
        $this->message = $message;

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
}