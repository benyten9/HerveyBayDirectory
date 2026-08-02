<?php

namespace DirectoristPricingPlan\App\Repositories;

defined( "ABSPATH" ) || exit;

use DirectoristPricingPlan\App\Repositories\Admin\PlanRepository;
use DirectoristPricingPlan\App\Repositories\UserPackageRepository;
use Directorist\Repositories\OrderRepository;

abstract class PackageUses {
    public PlanRepository $plan_repository;

    public OrderRepository $order_repository;

    public UserPackageRepository $user_package_repository;

    public function __construct( PlanRepository $plan_repository, OrderRepository $order_repository, UserPackageRepository $user_package_repository ) {
        $this->plan_repository         = $plan_repository;
        $this->order_repository        = $order_repository;
        $this->user_package_repository = $user_package_repository;
    }

    public function get_plan_by_id( int $plan_id ) {
        return $this->plan_repository->get_by_id( $plan_id );
    }
}