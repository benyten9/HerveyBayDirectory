<?php

namespace DirectoristPricingPlan\Database\Migrations;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;
use DirectoristPricingPlan\App\Enums\Plan\Interval as PlanInterval;
use DirectoristPricingPlan\App\Enums\UserPackage\Status as UserPackageStatus;
use DirectoristPricingPlan\WpMVC\Contracts\Migration;

class RepairV4LifetimePackageDatesMigration implements Migration {
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
                SET package.status = %s,
                    package.current_period_end = NULL,
                    package.cancelled_at = NULL
                WHERE plan.type = %s
                    AND plan.interval_type = %s
                    AND package.is_legacy = 1
                    AND (
                        package.status IN ( %s, %s )
                        OR package.current_period_end IS NOT NULL
                    )",
                UserPackageStatus::ACTIVE,
                PlanType::PACKAGE,
                PlanInterval::LIFETIME,
                UserPackageStatus::ACTIVE,
                UserPackageStatus::EXPIRED
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return true;
    }
}
