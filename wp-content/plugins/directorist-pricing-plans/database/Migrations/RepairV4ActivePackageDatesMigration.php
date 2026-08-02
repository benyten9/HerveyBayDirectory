<?php

namespace DirectoristPricingPlan\Database\Migrations;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;
use DirectoristPricingPlan\App\Enums\Plan\Interval as PlanInterval;
use DirectoristPricingPlan\App\Enums\UserPackage\Status as UserPackageStatus;
use DirectoristPricingPlan\WpMVC\Contracts\Migration;

class RepairV4ActivePackageDatesMigration implements Migration {
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

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are built from the WordPress table prefix and checked before use.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$packages_table}
                SET cancelled_at = NULL
                WHERE status = %s",
                UserPackageStatus::ACTIVE
            )
        );

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $plans_table ) ) !== $plans_table ) {
            return true;
        }

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$packages_table} AS package
                LEFT JOIN {$plans_table} AS plan ON plan.id = package.plan_id
                SET package.status = %s
                WHERE package.status = %s
                    AND package.current_period_end IS NOT NULL
                    AND package.current_period_end < %s
                    AND ( plan.type IS NULL OR plan.type != %s )
                    AND ( plan.interval_type IS NULL OR plan.interval_type != %s )",
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
