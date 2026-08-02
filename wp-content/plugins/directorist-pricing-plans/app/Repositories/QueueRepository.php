<?php

namespace DirectoristPricingPlan\App\Repositories;

defined( "ABSPATH" ) || exit;

use DirectoristPricingPlan\App\Models\Queue;
use DirectoristPricingPlan\WpMVC\Repositories\Repository;
use DirectoristPricingPlan\WpMVC\Database\Query\Builder;
use DirectoristPricingPlan\App\DTO\Queue\DTO;

class QueueRepository extends Repository {
    public function get_query_builder(): Builder {
        return Queue::query();
    }

    public function get_by_first_queue( ?string $task_type = null ) {
        $query = $this->get_query_builder();

        if ( $task_type ) {
            $query->where( 'task_type', $task_type );
        }

        return $query->first();
    }

    public function update_status( $id, $status ) {
        return $this->get_query_builder()->where( 'id', $id )->update( [ 'status' => $status ] );
    }

    /**
     * Create multiple queues
     *
     * @param array<DTO> $dtos
     * @return int
     */
    public function create_many( array $dtos ) {
        return Queue::query()->insert(
            array_map(
                function( DTO $dto ) {
                    return $dto->to_array();
                }, $dtos 
            ) 
        );
    }
}