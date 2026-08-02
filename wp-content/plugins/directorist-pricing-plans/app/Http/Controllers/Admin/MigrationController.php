<?php

namespace DirectoristPricingPlan\App\Http\Controllers\Admin;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use DirectoristPricingPlan\WpMVC\Routing\Response;
use DirectoristPricingPlan\App\Http\Controllers\Controller;
use DirectoristPricingPlan\App\Jobs\OldDataMigrationQueue;

class MigrationController extends Controller {
    /**
     * Check whether progress still has unmigrated records.
     *
     * @param array $progress
     * @return bool
     */
    private function has_remaining_items( array $progress ): bool {
        return ( $progress['plans_total'] > 0 && $progress['plans_migrated'] < $progress['plans_total'] )
            || ( $progress['orders_total'] > 0 && $progress['orders_migrated'] < $progress['orders_total'] )
            || ( $progress['users_total'] > 0 && $progress['users_migrated'] < $progress['users_total'] );
    }

    /**
     * Get migration queue status for admin polling.
     *
     * @param OldDataMigrationQueue $migration_queue
     * @return array
     */
    private function get_status_payload( OldDataMigrationQueue $migration_queue ): array {
        $progress      = $migration_queue->get_progress();
        $failed_count  = $migration_queue->get_failed_count();
        $has_remaining = $this->has_remaining_items( $progress );

        return [
            'active'        => $migration_queue->is_active(),
            'processing'    => $migration_queue->is_processing(),
            'finished'      => $migration_queue->is_finished(),
            'failed_count'  => $failed_count,
            'has_remaining' => $has_remaining,
            'progress'      => $progress,
        ];
    }

    /**
     * Restart an incomplete migration when it finished without a recorded step error.
     *
     * @param OldDataMigrationQueue $migration_queue
     * @return void
     */
    private function maybe_resume_incomplete_migration( OldDataMigrationQueue $migration_queue ): void {
        $progress = $migration_queue->get_progress();

        if ( $migration_queue->is_finished() && $this->has_remaining_items( $progress ) && 0 === $migration_queue->get_failed_count() ) {
            $migration_queue->retry_failed();
        }
    }

    /**
     * Start the migration.
     *
     * @param WP_REST_Request $request
     * @return array
     */
    public function start( WP_REST_Request $request ): array {
        /**
         * @var OldDataMigrationQueue $migration_queue
         */
        $migration_queue = directorist_pricing_plans_singleton( OldDataMigrationQueue::class );

        $migration_queue->start();

        return Response::send(
            [
                'message' => esc_html__( 'Migration has been started.', 'directorist-pricing-plans' ),
            ]
        );
    }

    /**
     * Advance and report migration progress for the admin notice poller.
     *
     * @param WP_REST_Request $request
     * @return array
     */
    public function status( WP_REST_Request $request ): array {
        /**
         * @var OldDataMigrationQueue $migration_queue
         */
        $migration_queue = directorist_pricing_plans_singleton( OldDataMigrationQueue::class );

        $this->maybe_resume_incomplete_migration( $migration_queue );

        if ( $migration_queue->is_active() && ! $migration_queue->is_processing() ) {
            $migration_queue->dispatch_or_process( true );
        }

        $this->maybe_resume_incomplete_migration( $migration_queue );

        return Response::send( $this->get_status_payload( $migration_queue ) );
    }

    /**
     * Retry failed migration steps.
     *
     * @param WP_REST_Request $request
     * @return array
     */
    public function retry( WP_REST_Request $request ): array {
        /**
         * @var OldDataMigrationQueue $migration_queue
         */
        $migration_queue = directorist_pricing_plans_singleton( OldDataMigrationQueue::class );

        $migration_queue->retry_failed();

        return Response::send(
            [
                'message' => esc_html__( 'Migration retry has been started.', 'directorist-pricing-plans' ),
            ]
        );
    }
}
