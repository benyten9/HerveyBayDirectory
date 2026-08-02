<?php

namespace DirectoristPricingPlan\Database\Migrations;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;
use DirectoristPricingPlan\App\Enums\Plan\Interval as PlanInterval;
use DirectoristPricingPlan\App\Enums\UserPackage\Status as UserPackageStatus;
use DirectoristPricingPlan\WpMVC\Contracts\Migration;

class RepairV4PackageStatusMigration implements Migration {
    public function more_than_version() {
        return '3.9.9';
    }

    public function execute(): bool {
        global $wpdb;

        $packages_table = "{$wpdb->prefix}directorist_user_packages";
        $plans_table    = "{$wpdb->prefix}directorist_plans";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $packages_table ) ) !== $packages_table ) {
            return true;
        }

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $plans_table ) ) !== $plans_table ) {
            return true;
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are built from the WordPress table prefix and checked before use.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$packages_table} AS package
                INNER JOIN {$plans_table} AS plan ON plan.id = package.plan_id
                SET package.current_period_end = NULL,
                    package.cancelled_at = NULL
                WHERE package.status = %s
                    AND plan.type = %s",
                UserPackageStatus::ACTIVE,
                PlanType::PAY_PER_LISTING
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$packages_table} AS package
                INNER JOIN {$plans_table} AS plan ON plan.id = package.plan_id
                SET package.cancelled_at = NULL
                WHERE package.status = %s
                    AND package.cancelled_at IS NOT NULL
                    AND plan.type != %s",
                UserPackageStatus::ACTIVE,
                PlanType::PAY_PER_LISTING
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$packages_table} AS package
                INNER JOIN {$plans_table} AS plan ON plan.id = package.plan_id
                SET package.status = %s
                WHERE package.status = %s
                    AND package.current_period_end IS NOT NULL
                    AND package.current_period_end < %s
                    AND plan.type != %s
                    AND plan.interval_type != %s",
                UserPackageStatus::EXPIRED,
                UserPackageStatus::ACTIVE,
                current_time( 'mysql' ),
                PlanType::PAY_PER_LISTING,
                PlanInterval::LIFETIME
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return true;
    }
}
