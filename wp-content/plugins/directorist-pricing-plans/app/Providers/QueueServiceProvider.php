<?php

namespace DirectoristPricingPlan\App\Providers;

defined( "ABSPATH" ) || exit;

use DirectoristPricingPlan\App\Jobs\UnassignedPlanOrderQueue;
use DirectoristPricingPlan\App\Jobs\OldDataMigrationQueue;
use DirectoristPricingPlan\WpMVC\Contracts\Provider;

class QueueServiceProvider implements Provider {
    private UnassignedPlanOrderQueue $unassigned_plan_order_queue;

    private OldDataMigrationQueue $old_data_migration_queue;

    public function boot() {
        // Initialize the queue early so its cron schedule filter is registered
        // This ensures the custom cron schedule exists before WordPress tries to use it
        $this->unassigned_plan_order_queue = directorist_pricing_plans_singleton( UnassignedPlanOrderQueue::class );
        $this->old_data_migration_queue    = directorist_pricing_plans_singleton( OldDataMigrationQueue::class );
    }
}

