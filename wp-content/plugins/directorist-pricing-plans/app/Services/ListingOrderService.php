<?php

namespace DirectoristPricingPlan\App\Services;

defined( 'ABSPATH' ) || exit;

use stdClass;
use Directorist\DTO\Order\DTO as OrderDTO;
use Directorist\Enums\Order\Status as OrderStatus;
use DirectoristPricingPlan\App\DTO\PlanOrderMeta\DTO as PlanOrderMetaDTO;
use DirectoristPricingPlan\App\Enums\Order\RefType as OrderRefType;
use DirectoristPricingPlan\App\Enums\Plan\Interval as PlanInterval;
use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;
use DirectoristPricingPlan\App\Repositories\PlanOrderMetaRepository;
use DirectoristPricingPlan\WpMVC\Exceptions\Exception;

class ListingOrderService {
    public function get_latest_order( int $listing_id, int $plan_id ): ?stdClass {
        $order = directorist_order_repository()->get_query_builder()
            ->where( 'listing_id', $listing_id )
            ->where( 'ref_type', OrderRefType::PRICING_PLAN )
            ->where( 'ref', (string) $plan_id )
            ->order_by_desc( 'id' )
            ->first();

        return $order ?: null;
    }

    public function assign_order( int $listing_id ): int {
        $context = $this->get_pay_per_listing_context( $listing_id );

        if ( 'expired' === get_post_status( $listing_id ) ) {
            throw new Exception(
                esc_html__( 'Expired listings do not require assigning an order.', 'directorist-pricing-plans' ),
                400
            );
        }

        $existing_order = $this->get_latest_order( $listing_id, (int) $context['plan']->id );

        if ( $existing_order ) {
            throw new Exception(
                esc_html__( 'This listing already has an assigned order.', 'directorist-pricing-plans' ),
                400
            );
        }

        return $this->create_order( $listing_id, $context['package'], $context['plan'], false );
    }

    public function renew_listing( int $listing_id ): int {
        $context = $this->get_pay_per_listing_context( $listing_id );

        if ( 'expired' !== get_post_status( $listing_id ) ) {
            throw new Exception(
                esc_html__( 'Only expired listings can be renewed.', 'directorist-pricing-plans' ),
                400
            );
        }

        return $this->create_order( $listing_id, $context['package'], $context['plan'], true );
    }

    public function create_assignment_order( int $listing_id, stdClass $package, stdClass $plan ): int {
        return $this->create_order( $listing_id, $package, $plan, false );
    }

    public function get_pay_per_listing_context( int $listing_id ): array {
        $listing = get_post( $listing_id );

        if ( ! $listing || ATBDP_POST_TYPE !== $listing->post_type ) {
            throw new Exception( esc_html__( 'Listing not found.', 'directorist-pricing-plans' ), 404 );
        }

        $package = directorist_get_listing_package( $listing_id );

        if ( ! $package ) {
            $plan_id = (int) get_post_meta( $listing_id, directorist_plan_key(), true );
            $package = $plan_id
                ? directorist_user_package_repository()->get_package_by_plan( (int) $listing->post_author, $plan_id )
                : null;
        }

        if ( ! $package ) {
            throw new Exception(
                esc_html__( 'No active package was found for this listing.', 'directorist-pricing-plans' ),
                404
            );
        }

        $plan = directorist_get_pricing_plan_by_id( (int) $package->plan_id );

        if ( ! $plan ) {
            throw new Exception(
                esc_html__( 'The plan associated with this package is not available anymore.', 'directorist-pricing-plans' ),
                404
            );
        }

        if ( PlanType::PAY_PER_LISTING !== ( $plan->type ?? PlanType::PACKAGE ) ) {
            throw new Exception(
                esc_html__( 'This action is only available for pay per listing packages.', 'directorist-pricing-plans' ),
                400
            );
        }

        return [
            'listing' => $listing,
            'package' => $package,
            'plan'    => $plan,
        ];
    }

    private function create_order( int $listing_id, stdClass $package, stdClass $plan, bool $publish_listing ): int {
        $order_dto = ( new OrderDTO() )
            ->set_user_id( (int) $package->user_id )
            ->set_listing_id( $listing_id )
            ->set_ref_type( OrderRefType::PRICING_PLAN )
            ->set_ref( (string) $plan->id )
            ->set_currency( atbdp_get_payment_currency() )
            ->set_status( OrderStatus::PAID )
            ->set_amount( (float) $plan->price )
            ->set_sub_total( (float) $plan->price );

        if ( ! empty( $plan->is_featured ) ) {
            $order_dto->set_is_featured_listing( true );
        }

        if ( ! empty( $plan->is_taxable ) ) {
            $order_dto->set_tax_type( $plan->tax_type )->set_tax_rate( (float) $plan->tax_rate );
        }

        $order_id = (int) directorist_order_repository()->create( $order_dto );

        if ( ! $order_id ) {
            throw new Exception(
                esc_html__( 'Failed to create order for this listing.', 'directorist-pricing-plans' ),
                400
            );
        }

        directorist_user_package_repository()->link_package_order( (int) $package->id, $order_id );
        $this->upsert_order_meta( $order_id, $plan );
        update_post_meta( $listing_id, directorist_plan_key(), (int) $plan->id );

        if ( $publish_listing ) {
            directorist_set_listing_featured( $listing_id, ! empty( $plan->is_featured ) );
            directorist_set_listing_status( $listing_id, 'publish' );
            directorist_pricing_plan_apply_plan_listing_expiration( $listing_id, $plan );
        }

        return $order_id;
    }

    private function upsert_order_meta( int $order_id, stdClass $plan ): void {
        $interval_type  = null;
        $interval_count = null;

        if ( PlanInterval::LIFETIME !== $plan->interval_type && (int) $plan->interval_count > 0 ) {
            $interval_type  = $plan->interval_type;
            $interval_count = (int) $plan->interval_count;
        }

        directorist_pricing_plans_singleton( PlanOrderMetaRepository::class )->upsert_by_order_id(
            ( new PlanOrderMetaDTO() )
                ->set_order_id( $order_id )
                ->set_is_recurring( false )
                ->set_is_trial( false )
                ->set_interval_type( $interval_type )
                ->set_interval_count( $interval_count )
        );
    }
}
