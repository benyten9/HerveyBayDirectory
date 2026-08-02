<?php

namespace DirectoristPricingPlan\App\Repositories;

defined( "ABSPATH" ) || exit;

use Directorist\DBModels\Order;
use DirectoristPricingPlan\App\Models\PackageOrder;
use DirectoristPricingPlan\WpMVC\Repositories\Repository;
use DirectoristPricingPlan\WpMVC\Database\Query\Builder;
use DirectoristPricingPlan\App\DTO\PackageOrder\DTO;
use DirectoristPricingPlan\App\DTO\PackageOrder\Read as PackageOrderRead;

class PackageOrderRepository extends Repository {
    public function get_query_builder(): Builder {
        return PackageOrder::query( 'package_order' );
    }

    public function get( PackageOrderRead $dto ): array {
        $query = $this->get_query_builder()
            ->select( 'package_order.id as package_order_id', 'd_order.*' )
            ->join( Order::get_table_name() . ' as d_order', 'package_order.order_id', '=', 'd_order.id' )
            ->where( 'package_order.package_id', $dto->get_package_id() );

        if ( $dto->get_search() ) {
            $query->where( 'd_order.id', "like", "%" . $dto->get_search() . "%" )
                ->or_where( 'd_order.status', "like", "%" . $dto->get_search() . "%" )
                ->or_where( 'd_order.created_at', "like", "%" . $dto->get_search() . "%" );
        }

        $count_query = clone $query;

        return [
            "total" => $count_query->count( 'd_order.id' ),
            "items" => $query->order_by_desc( 'd_order.id' )->pagination( $dto->get_page(), $dto->get_per_page() ),
        ];
    }

    public function to_dto( $package_order ) {
        return ( new DTO )
            ->set_id( $package_order->id )
            ->set_order_id( $package_order->order_id )
            ->set_package_id( $package_order->package_id );
    }
}

