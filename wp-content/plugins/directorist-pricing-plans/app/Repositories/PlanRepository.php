<?php

namespace DirectoristPricingPlan\App\Repositories;

defined( "ABSPATH" ) || exit;

use DirectoristPricingPlan\WpMVC\Repositories\Repository;
use DirectoristPricingPlan\WpMVC\Database\Query\Builder;
use DirectoristPricingPlan\App\DTO\Plan\DTO;
use DirectoristPricingPlan\App\Models\Plan;
use DirectoristPricingPlan\App\Enums\Plan\FeeType;
use DirectoristPricingPlan\App\Enums\Plan\Interval as PlanInterval;
use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;
use DirectoristPricingPlan\App\Repositories\Admin\PlanFeatureRepository;

class PlanRepository extends Repository {
    public PlanFeatureRepository $plan_feature_repository;

    public function __construct( PlanFeatureRepository $plan_feature_repository ) {
        $this->plan_feature_repository = $plan_feature_repository;
    }

    public function get_query_builder(): Builder {
        return Plan::query( "plan" );
    }

    public function get_by_directory_type( $directory_type_id, string $mode = 'all' ) {
        $query = $this->get_query_builder()->where( "plan.directory_type_id", $directory_type_id )
        ->left_join( "terms as directory_type_term", "directory_type_term.term_id", "=", "plan.directory_type_id" )
        ->where( "plan.is_published", 1 )
        ->where( "plan.is_hidden_from_plans_list", 0 )
        ->order_by( "plan.sort_order", "asc" );

        $active_package = directorist_get_current_package( $directory_type_id );

        // Filter plans based on mode for plan switching
        if ( 'plan-switch' === $mode && $active_package ) {
            $current_plan = directorist_get_pricing_plan_by_id( (int) $active_package->plan_id );

            if ( $current_plan && ( $current_plan->type ?? PlanType::PACKAGE ) === PlanType::PAY_PER_LISTING ) {
                // Pay per listing plans cannot switch — return empty
                return [];
            }

            // Package plans can only switch to other package plans
            $query->where( "plan.type", PlanType::PACKAGE );
        }

        $plans = $query->get();

        if ( ! $plans ) {
            return [];
        }

        $user_id                 = get_current_user_id();
        $has_used_trial          = $user_id ? directorist_user_has_used_trial( $user_id, $directory_type_id ) : true;
        $user_package_repository = directorist_user_package_repository();

        $plans = array_map(
            function( $plan ) use ( $active_package, $has_used_trial, $user_id, $user_package_repository ) {
                $plan->is_active = $active_package && $plan->id === $active_package->plan_id;

                if ( $plan->fee_type === FeeType::FREE ) {
                    $plan->price = 0;
                }

                // Add can_start_trial flag
                $plan->can_start_trial = ! empty( $plan->is_trial_enabled )
                    && $plan->fee_type !== FeeType::FREE
                    && ! $has_used_trial;

                // Mark free one-time plans (free + limited expiry) as already used if the
                // user has any prior package record for this plan (any status).
                $is_free_one_time         = $plan->fee_type === FeeType::FREE && $plan->interval_type !== PlanInterval::LIFETIME;
                $plan->exceeded_max_usage = ! $plan->is_active && $is_free_one_time && $user_id
                    ? $user_package_repository->has_ever_used_plan( $user_id, (int) $plan->id )
                    : false;

                return $plan;
            }, $plans
        );

        return $plans;
    }

    public function to_dto( $plan ) {
        if ( ! $plan ) {
            return null;
        }

        return ( new DTO() )
            ->set_id( $plan->id )
            ->set_title( $plan->title )
            ->set_description( $plan->description )
            ->set_directory_type_id( $plan->directory_type_id )
            ->set_allowed_listings( $plan->allowed_listings )
            ->set_is_allowed_unlimited_listings( $plan->is_allowed_unlimited_listings )
            ->set_allowed_featured_listings( $plan->allowed_featured_listings )
            ->set_is_allowed_unlimited_featured_listings( $plan->is_allowed_unlimited_featured_listings )
            ->set_fee_type( $plan->fee_type )
            ->set_price( $plan->price )
            ->set_is_taxable( $plan->is_taxable )
            ->set_tax_type( $plan->tax_type )
            ->set_tax_rate( $plan->tax_rate )
            ->set_interval_type( $plan->interval_type )
            ->set_interval_count( $plan->interval_count )
            ->set_is_subscription_enabled( $plan->is_subscription_enabled )
            ->set_is_trial_enabled( $plan->is_trial_enabled )
            ->set_trial_interval_type( $plan->trial_interval_type )
            ->set_trial_interval_count( $plan->trial_interval_count )
            ->set_sort_order( $plan->sort_order )
            ->set_is_published( $plan->is_published )
            ->set_is_hidden_from_plans_list( $plan->is_hidden_from_plans_list )
            ->set_is_marked_as_recommended( $plan->is_marked_as_recommended )
            ->set_is_fallback( (bool) $plan->is_fallback )
            ->set_fallback_plan_id( (int) $plan->fallback_plan_id )
            ->set_listing_display_priority( (int) $plan->listing_display_priority )
            ->set_type( $plan->type ?? 'package' )
            ->set_is_featured( (bool) ( $plan->is_featured ?? false ) );
    }
}