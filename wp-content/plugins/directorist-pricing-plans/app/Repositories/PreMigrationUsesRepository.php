<?php

namespace DirectoristPricingPlan\App\Repositories;

defined( 'ABSPATH' ) || exit;

use stdClass;
use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;
use DirectoristPricingPlan\App\Enums\UserPackage\Status as UserPackageStatus;
use DirectoristPricingPlan\App\Models\Plan;
use DirectoristPricingPlan\App\Models\Post;
use DirectoristPricingPlan\App\Models\UserPackage;
use DirectoristPricingPlan\WpMVC\Database\Query\JoinClause;

/**
 * Directory-scoped usage retained only while listing plan meta is being migrated.
 */
class PreMigrationUsesRepository extends UsesRepository {
    public function get_regular_uses( int $listing_owner_id, stdClass $plan ): array {
        if ( PlanType::PAY_PER_LISTING === ( $plan->type ?? '' ) ) {
            return [
                'allowed'   => -1,
                'used'      => $this->get_regular_listings_count( $listing_owner_id, (int) $plan->directory_type_id ),
                'remaining' => -1,
            ];
        }

        $uses = [
            'allowed' => $this->get_plan_allowed_listings( $plan ),
            'used'    => $this->get_regular_listings_count( $listing_owner_id, (int) $plan->directory_type_id )
                + $this->get_featured_listings_count( $listing_owner_id, (int) $plan->directory_type_id ),
        ];

        $uses['remaining'] = $this->get_remaining_listings( $uses['allowed'], $uses['used'] );

        return $uses;
    }

    public function get_featured_uses( int $listing_owner_id, stdClass $plan ): array {
        if ( PlanType::PAY_PER_LISTING === ( $plan->type ?? '' ) ) {
            return [
                'allowed'   => -1,
                'used'      => $this->get_featured_listings_count( $listing_owner_id, (int) $plan->directory_type_id ),
                'remaining' => -1,
            ];
        }

        $uses = [
            'allowed' => $this->get_plan_allowed_featured_listings( $plan ),
            'used'    => $this->get_featured_listings_count( $listing_owner_id, (int) $plan->directory_type_id ),
        ];

        $uses['remaining'] = $this->get_remaining_listings( $uses['allowed'], $uses['used'] );

        return $uses;
    }

    public function get_total_uses( int $listing_owner_id, stdClass $plan ): array {
        if ( PlanType::PAY_PER_LISTING === ( $plan->type ?? '' ) ) {
            return [
                'allowed'   => -1,
                'used'      => 0,
                'remaining' => -1,
            ];
        }

        $uses = [
            'allowed' => $this->get_plan_allowed_listings( $plan ),
            'used'    => $this->get_regular_listings_count( $listing_owner_id, (int) $plan->directory_type_id )
                + $this->get_featured_listings_count( $listing_owner_id, (int) $plan->directory_type_id ),
        ];

        $uses['remaining'] = $this->get_remaining_listings( $uses['allowed'], $uses['used'] );

        return $uses;
    }

    public function get_regular_listings_count( int $listing_owner_id, int $directory_type_id ): int {
        return Post::query()
            ->join(
                'postmeta',
                function ( JoinClause $join ) use ( $directory_type_id ) {
                    $join->on_column( 'postmeta.post_id', '=', 'posts.ID' )
                        ->on( 'postmeta.meta_key', '=', '_directory_type' )
                        ->on( 'postmeta.meta_value', '=', $directory_type_id );
                }
            )
            ->left_join(
                'postmeta as plan_id_meta',
                function ( JoinClause $join ) {
                    $join->on_column( 'plan_id_meta.post_id', '=', 'posts.ID' )
                        ->on( 'plan_id_meta.meta_key', '=', '_plan_id' );
                }
            )
            ->left_join(
                'postmeta as featured_meta',
                function ( JoinClause $join ) {
                    $join->on_column( 'featured_meta.post_id', '=', 'posts.ID' )
                        ->on( 'featured_meta.meta_key', '=', '_featured' );
                }
            )
            ->left_join(
                UserPackage::get_table_name() . ' as active_legacy_package',
                function ( JoinClause $join ) use ( $listing_owner_id, $directory_type_id ) {
                    $join->on_column( 'active_legacy_package.plan_id', '=', 'plan_id_meta.meta_value' )
                        ->on( 'active_legacy_package.user_id', '=', $listing_owner_id )
                        ->on( 'active_legacy_package.directory_type_id', '=', $directory_type_id )
                        ->on( 'active_legacy_package.is_legacy', '=', 1 )
                        ->on_in( 'active_legacy_package.status', [ UserPackageStatus::ACTIVE, UserPackageStatus::CANCELLED_AT_PERIOD_END ] );
                }
            )
            ->left_join( Plan::get_table_name() . ' as active_legacy_plan', 'active_legacy_package.plan_id', '=', 'active_legacy_plan.id' )
            ->where( 'post_type', '=', ATBDP_POST_TYPE )
            ->where( 'post_status', '=', 'publish' )
            ->where( 'post_author', '=', $listing_owner_id )
            ->where_null( 'active_legacy_plan.id' )
            ->where(
                function ( $query ) {
                    $query->where_null( 'featured_meta.post_id' )
                        ->or_where( 'featured_meta.meta_value', '!=', '1' );
                }
            )
            ->count();
    }

    public function get_featured_listings_count( int $listing_owner_id, int $directory_type_id ): int {
        return Post::query()
            ->join(
                'postmeta',
                function ( JoinClause $join ) {
                    $join->on_column( 'postmeta.post_id', '=', 'posts.ID' )
                        ->on( 'postmeta.meta_key', '=', '_featured' )
                        ->on( 'postmeta.meta_value', '=', '1' );
                }
            )
            ->join(
                'postmeta as directory_meta',
                function ( JoinClause $join ) use ( $directory_type_id ) {
                    $join->on_column( 'directory_meta.post_id', '=', 'posts.ID' )
                        ->on( 'directory_meta.meta_key', '=', '_directory_type' )
                        ->on( 'directory_meta.meta_value', '=', $directory_type_id );
                }
            )
            ->left_join(
                'postmeta as plan_id_meta',
                function ( JoinClause $join ) {
                    $join->on_column( 'plan_id_meta.post_id', '=', 'posts.ID' )
                        ->on( 'plan_id_meta.meta_key', '=', '_plan_id' );
                }
            )
            ->left_join(
                UserPackage::get_table_name() . ' as active_legacy_package',
                function ( JoinClause $join ) use ( $listing_owner_id, $directory_type_id ) {
                    $join->on_column( 'active_legacy_package.plan_id', '=', 'plan_id_meta.meta_value' )
                        ->on( 'active_legacy_package.user_id', '=', $listing_owner_id )
                        ->on( 'active_legacy_package.directory_type_id', '=', $directory_type_id )
                        ->on( 'active_legacy_package.is_legacy', '=', 1 )
                        ->on_in( 'active_legacy_package.status', [ UserPackageStatus::ACTIVE, UserPackageStatus::CANCELLED_AT_PERIOD_END ] );
                }
            )
            ->left_join( Plan::get_table_name() . ' as active_legacy_plan', 'active_legacy_package.plan_id', '=', 'active_legacy_plan.id' )
            ->where( 'post_type', '=', ATBDP_POST_TYPE )
            ->where( 'post_status', '=', 'publish' )
            ->where( 'post_author', '=', $listing_owner_id )
            ->where_null( 'active_legacy_plan.id' )
            ->count();
    }
}
