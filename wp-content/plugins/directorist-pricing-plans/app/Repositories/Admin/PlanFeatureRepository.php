<?php

namespace DirectoristPricingPlan\App\Repositories\Admin;

defined( "ABSPATH" ) || exit;

use stdClass;
use DirectoristPricingPlan\App\Models\PlanFeature;
use DirectoristPricingPlan\WpMVC\Repositories\Repository;
use DirectoristPricingPlan\WpMVC\Database\Query\Builder;
use DirectoristPricingPlan\WpMVC\Helpers\Helpers;
use DirectoristPricingPlan\App\DTO\PlanFeature\DTO as PlanFeatureDTO;

class PlanFeatureRepository extends Repository {
    public function get_query_builder(): Builder {
        return PlanFeature::query();
    }

    public function get( stdClass $plan ) {
        $db_features         = $this->get_query_builder()->select( "id", "feature_key", "is_enabled", "is_show_in_pricing_table", "data" )->where( "plan_id", $plan->id )->order_by( "sort_order", "asc" )->order_by( "id" )->get();
        $registered_features = directorist_plan_registered_features( $plan->directory_type_id );
        $features_data       = [];
        $i                   = 1;

        foreach ( $db_features as $feature ) {
            $feature_key = (string) $feature->feature_key;

            if ( isset( $registered_features[ $feature_key ] ) ) {
                $feature->data = ! empty( $feature->data ) ? json_decode( $feature->data, true ) : [];
                $data          = (object) Helpers::array_merge_deep( $registered_features[ $feature_key ], $feature );

                $data->is_enabled               = (bool) $data->is_enabled;
                $data->is_show_in_pricing_table = (bool) $data->is_show_in_pricing_table;
                $data->key                      = $feature_key;
                $data->id                       = $feature->id;
                $features_data[]                = $data;
                unset( $registered_features[ $feature_key ] );
            }
        }

        foreach ( $registered_features as $feature_key => $feature ) {
            $data            = (object) $feature;
            $data->key       = (string) $feature_key;
            $data->id        = $i;
            $features_data[] = $data;
            $i++;
        }

        return $features_data;
    }

    public function update_features( $features, $plan_id ) {
        $db_features     = $this->get_query_builder()->select( 'id', 'feature_key' )->where( 'plan_id', $plan_id )->get();
        $db_features_map = [];

        foreach ( $db_features as $db_feature ) {
            $db_features_map[ (string) $db_feature->feature_key ] = $db_feature;
        }

        $sort_order = 1;

        foreach ( $features as $feature ) {
            $feature     = (object) $feature;
            $feature_key = (string) $feature->key;

            $dto = ( new PlanFeatureDTO() )
            ->set_is_enabled( (bool) $feature->is_enabled )
            ->set_is_show_in_pricing_table( (bool) $feature->is_show_in_pricing_table )
            ->set_data( isset( $feature->data ) && is_array( $feature->data ) ? $feature->data : [] )
            ->set_sort_order( $sort_order );

            if ( isset( $db_features_map[ $feature_key ] ) ) {
                $this->get_query_builder()->where( 'id', $db_features_map[ $feature_key ]->id )->update( $this->process_values( $dto->to_array() ) );
            } else {
                $dto->set_plan_id( $plan_id )->set_feature_key( $feature_key );
                $this->get_query_builder()->insert( $this->process_values( $dto->to_array() ) );
            }

            $sort_order++;
        }
    }
}
