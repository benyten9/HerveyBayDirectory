<?php

namespace DirectoristPricingPlan\App\Repositories;

defined( "ABSPATH" ) || exit;

use DirectoristPricingPlan\WpMVC\Database\Query\Builder;
use DirectoristPricingPlan\WpMVC\Repositories\Repository;
use DirectoristPricingPlan\App\DTO\SubscriptionLog\DTO;
use DirectoristPricingPlan\App\Models\SubscriptionLog;

class SubscriptionLogRepository extends Repository {
    public function get_query_builder(): Builder {
        return SubscriptionLog::query( 'log' );
    }

    /**
     * Create a new subscription log entry
     *
     * @param DTO $dto 
     * @return int The created log ID
     */
    public function create( \DirectoristPricingPlan\WpMVC\DTO\DTO $dto ): int {
        $data = [
            'package_id'  => $dto->get_package_id(),
            'action'      => $dto->get_action(),
            'triggered_by' => $dto->get_triggered_by(),
            'description' => $dto->get_description(),
        ];

        return $this->get_query_builder()->insert_get_id( $data );
    }

    /**
     * Get logs by package ID with pagination
     *
     * @param int $package_id 
     * @return array
     */
    public function get_by_package_id( int $package_id ): array {
        $logs = $this->get_query_builder()
            ->where( 'log.package_id', $package_id )
            ->order_by( 'log.created_at', 'DESC' )
            ->get();
        
        return [ 'items' => $logs ];
    }
}

