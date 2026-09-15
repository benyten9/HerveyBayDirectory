<?php

namespace DirectoristPricingPlan\App\Services;

defined( 'ABSPATH' ) || exit;

use Directorist\DTO\Order\DTO as OrderDTO;
use Directorist\Enums\Order\Status as OrderStatus;
use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;
use DirectoristPricingPlan\App\Enums\UserPackage\Status as UserPackageStatus;
use DirectoristPricingPlan\App\Repositories\UserPackageRepository;
use DirectoristPricingPlan\WpMVC\Exceptions\Exception;

class SubscriptionPaymentService {
    private UserPackageRepository $package_repository;

    public function __construct( UserPackageRepository $package_repository ) {
        $this->package_repository = $package_repository;
    }

    public function recheck( int $package_id, string $triggered_by = 'system' ): ?int {
        $package = $this->get_recheckable_package( $package_id );

        $renewal_order = apply_filters(
            "directorist_{$package->subscription_method}_renewal_payment",
            null,
            (string) $package->subscription_id
        );

        // Gateways may renew the package while resolving the payment.
        $package = $this->package_repository->get_by_id( $package_id );

        if ( $this->was_renewed( $package ) ) {
            return (int) $package->last_order_id;
        }

        $renewal_order = $this->normalize_renewal_order( $renewal_order );

        if ( ! $renewal_order || OrderStatus::PAID !== $renewal_order->get_status() ) {
            return null;
        }

        return $this->package_repository->renew( $package_id, $renewal_order->get_id(), $triggered_by );
    }

    private function get_recheckable_package( int $package_id ): \stdClass {
        $package = $this->package_repository->get_by_id( $package_id );

        if ( ! $package ) {
            throw new Exception( esc_html__( 'Package not found.', 'directorist-pricing-plans' ), 404 );
        }

        $plan = directorist_get_pricing_plan_by_id( (int) $package->plan_id );

        if ( ! $plan || PlanType::PACKAGE !== ( $plan->type ?? PlanType::PACKAGE ) || empty( $package->is_recurring ) ) {
            throw new Exception( esc_html__( 'Only recurring subscription packages can re-check payment.', 'directorist-pricing-plans' ), 400 );
        }

        if ( ! $this->is_past_due( $package ) ) {
            throw new Exception( esc_html__( 'Only past due packages can re-check payment.', 'directorist-pricing-plans' ), 400 );
        }

        if ( empty( $package->subscription_method ) || empty( $package->subscription_id ) ) {
            throw new Exception( esc_html__( 'The package does not have valid subscription payment details.', 'directorist-pricing-plans' ), 400 );
        }

        return $package;
    }

    private function is_past_due( \stdClass $package ): bool {
        if ( UserPackageStatus::PAST_DUE === $package->status ) {
            return true;
        }

        if ( UserPackageStatus::ACTIVE !== $package->status ) {
            return false;
        }

        $period_end = $this->package_repository->to_dto( $package )->get_current_period_end();

        return $period_end && $period_end->getTimestamp() <= directorist_now()->getTimestamp();
    }

    private function was_renewed( ?\stdClass $package ): bool {
        if ( ! $package || UserPackageStatus::ACTIVE !== $package->status ) {
            return false;
        }

        $period_end = $this->package_repository->to_dto( $package )->get_current_period_end();

        return $period_end && $period_end->getTimestamp() > directorist_now()->getTimestamp();
    }

    private function normalize_renewal_order( $order ): ?OrderDTO {
        if ( $order instanceof OrderDTO ) {
            return $order;
        }

        if ( is_object( $order ) && ! empty( $order->id ) ) {
            return directorist_order_repository()->to_dto( $order );
        }

        if ( is_numeric( $order ) ) {
            $order = directorist_order_repository()->get_by_id( (int) $order );

            return $order ? directorist_order_repository()->to_dto( $order ) : null;
        }

        return null;
    }
}
