<?php

namespace DirectoristPricingPlan\App\Repositories;

defined( 'ABSPATH' ) || exit;

use stdClass;
use DirectoristPricingPlan\App\Contracts\PackageUsageInterface;
use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;
use DirectoristPricingPlan\App\Models\Post;
use DirectoristPricingPlan\WpMVC\Database\Query\JoinClause;

class LegacyUsesRepository extends PackageUses implements PackageUsageInterface {

    public function has_plan_remaining_quota( stdClass $plan, bool $is_featured_listing ): bool {
        $total_uses = $this->get_total_uses( get_current_user_id(), $plan );

        if ( $total_uses['remaining'] !== -1 && $total_uses['remaining'] <= 0 ) {
            return false;
        }

        if ( $is_featured_listing ) {
            $uses = $this->get_featured_uses( get_current_user_id(), $plan );

            return $uses['remaining'] === -1 || $uses['remaining'] > 0;
        }

        return true;
    }

    public function get_uses_by_plan( int $listing_owner_id, stdClass $plan ): array {
        return [
            'listings'          => $this->get_regular_uses( $listing_owner_id, $plan ),
            'featured_listings' => $this->get_featured_uses( $listing_owner_id, $plan ),
        ];
    }

    public function get_listings_uses( int $listing_owner_id, stdClass $plan, bool $is_featured_listing ): array {
        if ( $is_featured_listing ) {
            return $this->get_featured_uses( $listing_owner_id, $plan );
        }

        return $this->get_regular_uses( $listing_owner_id, $plan );
    }

    public function get_regular_uses( int $listing_owner_id, stdClass $plan ): array {
        if ( PlanType::PAY_PER_LISTING === ( $plan->type ?? '' ) ) {
            return [
                'allowed'   => -1,
                'used'      => $this->get_regular_listings_count( $listing_owner_id, (int) $plan->id ),
                'remaining' => -1,
            ];
        }

        $uses = [
            'allowed' => $this->get_plan_allowed_listings( $plan ),
            'used'    => $this->get_regular_listings_count( $listing_owner_id, (int) $plan->id ) + $this->get_featured_listings_count( $listing_owner_id, (int) $plan->id ),
        ];

        $uses['remaining'] = $this->get_remaining_listings( $uses['allowed'], $uses['used'] );

        return $uses;
    }

    public function get_featured_uses( int $listing_owner_id, stdClass $plan ): array {
        if ( PlanType::PAY_PER_LISTING === ( $plan->type ?? '' ) ) {
            return [
                'allowed'   => -1,
                'used'      => $this->get_featured_listings_count( $listing_owner_id, (int) $plan->id ),
                'remaining' => -1,
            ];
        }

        $uses = [
            'allowed' => $this->get_plan_allowed_featured_listings( $plan ),
            'used'    => $this->get_featured_listings_count( $listing_owner_id, (int) $plan->id ),
        ];

        $uses['remaining'] = $this->get_remaining_listings( $uses['allowed'], $uses['used'] );

        return $uses;
    }

    public function get_regular_listings_count( int $listing_owner_id, int $plan_id ): int {
        return Post::query()
            ->join(
                'postmeta',
                function( JoinClause $join ) use ( $plan_id ) {
                    $join->on_column( 'postmeta.post_id', '=', 'posts.ID' )
                        ->on( 'postmeta.meta_key', '=', '_plan_id' )
                        ->on( 'postmeta.meta_value', '=', $plan_id );
                }
            )
            ->left_join(
                'postmeta as featured_meta',
                function( JoinClause $join ) {
                    $join->on_column( 'featured_meta.post_id', '=', 'posts.ID' )
                        ->on( 'featured_meta.meta_key', '=', '_featured' );
                }
            )
            ->where( 'post_type', '=', ATBDP_POST_TYPE )
            ->where( 'post_status', '=', 'publish' )
            ->where( 'post_author', '=', $listing_owner_id )
            ->where( function( $query ) {
                $query->where_null( 'featured_meta.post_id' )
                    ->or_where( 'featured_meta.meta_value', '!=', '1' );
            } )
            ->count();
    }

    public function get_featured_listings_count( int $listing_owner_id, int $plan_id ): int {
        return Post::query()
            ->join(
                'postmeta',
                function( JoinClause $join ) use ( $plan_id ) {
                    $join->on_column( 'postmeta.post_id', '=', 'posts.ID' )
                        ->on( 'postmeta.meta_key', '=', '_plan_id' )
                        ->on( 'postmeta.meta_value', '=', $plan_id );
                }
            )
            ->join(
                'postmeta as featured_meta',
                function( JoinClause $join ) {
                    $join->on_column( 'featured_meta.post_id', '=', 'posts.ID' )
                        ->on( 'featured_meta.meta_key', '=', '_featured' )
                        ->on( 'featured_meta.meta_value', '=', '1' );
                }
            )
            ->where( 'post_type', '=', ATBDP_POST_TYPE )
            ->where( 'post_status', '=', 'publish' )
            ->where( 'post_author', '=', $listing_owner_id )
            ->count();
    }

    public function get_plan_allowed_listings( stdClass $plan ): int {
        return $plan->is_allowed_unlimited_listings ? -1 : (int) $plan->allowed_listings;
    }

    public function get_plan_allowed_regular_listings( stdClass $plan ): int {
        return $this->get_plan_allowed_listings( $plan );
    }

    public function get_plan_allowed_featured_listings( stdClass $plan ): int {
        return $plan->is_allowed_unlimited_featured_listings ? -1 : (int) $plan->allowed_featured_listings;
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
            'used'    => $this->get_regular_listings_count( $listing_owner_id, (int) $plan->id ) + $this->get_featured_listings_count( $listing_owner_id, (int) $plan->id ),
        ];

        $uses['remaining'] = $this->get_remaining_listings( $uses['allowed'], $uses['used'] );

        return $uses;
    }

    public function get_remaining_listings( int $allowed, int $used ): int {
        if ( $allowed === -1 ) {
            return -1;
        }

        $remaining = $allowed - $used;

        return $remaining < 0 ? 0 : $remaining;
    }
}
