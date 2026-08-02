<?php

namespace DirectoristPricingPlan\App\Repositories\Admin;

defined( "ABSPATH" ) || exit;

use DirectoristPricingPlan\App\Models\PlanAppConfiguration;
use DirectoristPricingPlan\WpMVC\Database\Query\Builder;
use DirectoristPricingPlan\WpMVC\Repositories\Repository;

class PlanAppConfigurationRepository extends Repository {
    public function get_query_builder(): Builder {
        return PlanAppConfiguration::query();
    }

    public function get_by_plan_id( int $plan_id ): array {
        return $this->get_query_builder()
            ->select( 'id', 'plan_id', 'type', 'product_id', 'product_price' )
            ->where( 'plan_id', $plan_id )
            ->order_by( 'id' )
            ->get();
    }

    public function update_configurations( array $configurations, int $plan_id ): void {
        $supported_types = array_column( directorist_pricing_plans_config( 'plan-app-configurations' ), 'type' );

        $this->get_query_builder()->where( 'plan_id', $plan_id )->delete();

        foreach ( $configurations as $configuration ) {
            $configuration = (object) $configuration;
            $type          = isset( $configuration->type ) ? sanitize_key( $configuration->type ) : '';

            if ( ! in_array( $type, $supported_types, true ) ) {
                continue;
            }

            $product_id    = isset( $configuration->product_id ) ? sanitize_text_field( (string) $configuration->product_id ) : '';
            $product_price = isset( $configuration->product_price ) ? sanitize_text_field( (string) $configuration->product_price ) : '';

            if ( '' === $product_id && '' === $product_price ) {
                continue;
            }

            $this->get_query_builder()->insert(
                [
                    'plan_id'       => $plan_id,
                    'type'          => $type,
                    'product_id'    => $product_id,
                    'product_price' => $product_price,
                ]
            );
        }
    }
}
