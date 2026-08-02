<?php

namespace DirectoristPricingPlan\Database\Migrations;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\App\Enums\Order\RefType;
use DirectoristPricingPlan\App\Enums\Plan\Interval;
use DirectoristPricingPlan\WpMVC\Contracts\Migration;

class RepairV4PendingPlanOrderMetaMigration implements Migration {
    public function more_than_version() {
        return '3.9.9';
    }

    public function execute(): bool {
        global $wpdb;

        $orders_table     = "{$wpdb->prefix}directorist_orders";
        $plans_table      = "{$wpdb->prefix}directorist_plans";
        $order_meta_table = "{$wpdb->prefix}directorist_plan_order_meta";

        foreach ( [ $orders_table, $plans_table, $order_meta_table ] as $table ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
                return true;
            }
        }

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$order_meta_table} (order_id, is_recurring, is_trial, interval_type, interval_count, current_period_end)
                SELECT orders.id,
                    plan.is_subscription_enabled,
                    0,
                    CASE WHEN plan.interval_type = %s THEN NULL ELSE plan.interval_type END,
                    CASE WHEN plan.interval_type = %s THEN NULL ELSE plan.interval_count END,
                    NULL
                FROM {$orders_table} AS orders
                INNER JOIN {$plans_table} AS plan ON plan.id = CAST(orders.ref AS UNSIGNED)
                LEFT JOIN {$order_meta_table} AS order_meta ON order_meta.order_id = orders.id
                WHERE orders.ref_type = %s
                    AND orders.status = 'pending'
                    AND order_meta.id IS NULL",
                Interval::LIFETIME,
                Interval::LIFETIME,
                RefType::PRICING_PLAN
            )
        );

        return true;
    }
}
