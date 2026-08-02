<?php

namespace DirectoristPricingPlan\App\Repositories;

defined( "ABSPATH" ) || exit;

use DirectoristPricingPlan\App\DTO\PlanOrderMeta\DTO;
use DirectoristPricingPlan\App\Models\PlanOrderMeta;
use DirectoristPricingPlan\WpMVC\Database\Query\Builder;
use DirectoristPricingPlan\WpMVC\Repositories\Repository;

class PlanOrderMetaRepository extends Repository {
    public function get_query_builder(): Builder {
        return PlanOrderMeta::query( 'plan_order_meta' );
    }

    public function get_by_order_id( int $order_id ) {
        return $this->get_query_builder()->where( 'order_id', $order_id )->first();
    }

    public function upsert_by_order_id( DTO $dto ): int {
        $existing = $this->get_by_order_id( $dto->get_order_id() );

        if ( ! $existing ) {
            return (int) $this->create( $dto );
        }

        $dto->set_id( (int) $existing->id );
        $this->update( $dto );

        return (int) $existing->id;
    }

    public function to_dto( $order_meta ): DTO {
        return ( new DTO )
            ->set_id( (int) $order_meta->id )
            ->set_order_id( (int) $order_meta->order_id )
            ->set_is_recurring( ! empty( $order_meta->is_recurring ) )
            ->set_is_trial( ! empty( $order_meta->is_trial ) )
            ->set_interval_type( $order_meta->interval_type )
            ->set_interval_count( null !== $order_meta->interval_count ? (int) $order_meta->interval_count : null )
            ->set_current_period_end( $this->to_nullable_datetime( $order_meta->current_period_end ?? null ) );
    }

    private function to_nullable_datetime( $value ): ?\Directorist\Helpers\DateTime {
        if ( empty( $value ) || '0000-00-00 00:00:00' === $value ) {
            return null;
        }

        return new \Directorist\Helpers\DateTime( $value );
    }
}
