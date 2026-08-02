<?php

namespace DirectoristPricingPlan\App\Providers;

defined( "ABSPATH" ) || exit;

use DirectoristPricingPlan\WpMVC\Contracts\Provider;

use DirectoristPricingPlan\App\Enums\UserPackage\Status as UserPackageStatus;
use DirectoristPricingPlan\App\DTO\UserPackage\DTO as UserPackageDTO;
use DirectoristPricingPlan\App\DTO\SubscriptionLog\DTO as SubscriptionLogDTO;
use DirectoristPricingPlan\App\Repositories\SubscriptionLogRepository;

class PackageLogProvider implements Provider {
    private $subscription_log_repository;

    public function __construct( SubscriptionLogRepository $subscription_log_repository ) {
        $this->subscription_log_repository = $subscription_log_repository;
    }

    public function boot() {
        add_action( 'directorist_package_created', [ $this, 'insert_package_log' ], 10, 1 );
        add_action( 'directorist_package_updated', [ $this, 'insert_package_log' ], 10, 3 );
    }
    
    public function insert_package_log( UserPackageDTO $package_dto, ?UserPackageDTO $old_package_dto = null, ?string $triggered_by = null ) {            
        $triggered_by = $triggered_by ?? 'system';
        $description  = $this->get_description( $package_dto, $old_package_dto, $triggered_by );
        
        if ( null === $description ) {
            return;
        }

        $log_dto = ( new SubscriptionLogDTO() )
          ->set_package_id( $package_dto->get_id() )
          ->set_action( $package_dto->get_status() )
          ->set_triggered_by( $triggered_by )
          ->set_description( $description );

        $this->subscription_log_repository->create( $log_dto );
    }

    public function get_description( UserPackageDTO $package_dto, ?UserPackageDTO $old_package_dto = null, ?string $triggered_by = null ): ?string {
        $package_type = $package_dto->is_is_recurring() ? 'subscription' : 'package';
    
        switch ( $package_dto->get_status() ) {
            case UserPackageStatus::ACTIVE:
                $order_id   = $package_dto->get_last_order_id();
                $order_info = $order_id ? sprintf( '. Linked to Order #%d', $order_id ) : null;

                if ( $old_package_dto ) {
                    return \sprintf( 'The %s has been renewed%s', $package_type, $order_info );
                } else {
                    return  \sprintf( 'The %s has been activated%s', $package_type, $order_info );
                }
            case UserPackageStatus::CANCELLED:
                return sprintf( 'The %s has been cancelled by %s', $package_type, $triggered_by );
            case UserPackageStatus::CANCELLED_AT_PERIOD_END:
                 return sprintf( 'The %s has been scheduled to be cancelled at period end by %s', $package_type, $triggered_by );
            case UserPackageStatus::EXPIRED:
                return \sprintf( 'The %s has been expired', $package_type );
            case UserPackageStatus::ARCHIVED:
                return \sprintf( 'The %s has been archived', $package_type );
        }

        return null;
    }
}