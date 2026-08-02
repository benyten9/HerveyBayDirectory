<?php

namespace DirectoristPricingPlan\App\Jobs;

defined( "ABSPATH" ) || exit;

use stdClass;
use Directorist\DTO\Order\DTO as OrderDTO;
use Directorist\Enums\Order\Status as OrderStatus;

use DirectoristPricingPlan\WpMVC\Exceptions\Exception;
use DirectoristPricingPlan\App\Enums\Plan\FeeType as PlanFeeType;
use DirectoristPricingPlan\App\Enums\Order\RefType as OrderRefType;
use DirectoristPricingPlan\App\Enums\Queue\Status as QueueStatus;
use DirectoristPricingPlan\App\DTO\Queue\DTO as QueueDTO;
use DirectoristPricingPlan\App\DTO\UserPackage\Activation as UserPackageActivationDTO;
use DirectoristPricingPlan\App\Repositories\QueueRepository;
use DirectoristPricingPlan\App\Repositories\Admin\PlanRepository;

class UnassignedPlanOrderQueue extends BaseSequence {
    protected $prefix = 'directorist_plans';

    protected $action = 'unassigned_plan_order_processor';

    protected function triggered_error( ?array $error ) {
        update_option( 'directorist_plans_lock_unassigned_plan_order_process', 1 );
    }

    protected function get_item( $item ) {
        $dto = $this->get_current_queue( $item );

        if ( ! $dto ) {
            return false;
        }

        $item['queue'] = $dto;
        return $item;
    }

    protected function perform_sequence_task( $item ) {
        if ( empty( $item['queue'] ) ) {
            return true;
        }
        
        /**
         * @var QueueRepository $queue_repository
         */
        $queue_repository = directorist_pricing_plans_singleton( QueueRepository::class );

        /**
        * @var QueueDTO $queue
        */
        $queue     = $item['queue'];
        $task_data = $queue->get_task_data();
        $user_id   = $queue->get_task_id();

        if ( empty( $user_id ) || empty( $task_data['assignments'] ) || empty( $task_data['directory_type_ids'] ) ) {
            $queue_repository->delete_by_id( $queue->get_id() );
            return false;
        }

        $currency = atbdp_get_payment_currency();

        foreach ( $task_data['directory_type_ids'] as $directory_type_id ) {
            try {
                $assignment   = $this->get_directory_plan_assignment( $directory_type_id, $task_data['assignments'] );
                $plan         = directorist_get_pricing_plan_by_id( $assignment['plan_id'] );

                if ( ! $plan ) {
                    throw new Exception( sprintf( 'Plan %d is not available anymore', $assignment['plan_id'] ) );
                }

                $order_status = $plan->fee_type === PlanFeeType::FREE ? OrderStatus::PAID : $assignment['order_status'];

                if ( $plan->directory_type_id !== $directory_type_id ) {
                    throw new Exception( sprintf( 'Plan %d is not supported by directory type %d', $plan->id, $directory_type_id ) );
                }

                $order_id = $this->create_order( $user_id, $plan, $order_status, $currency );

                if ( $order_id && $order_status === OrderStatus::PAID ) {
                    $this->activate_package( $user_id, $plan, $order_id );
                }
            } catch ( Exception $ex ) {
                $queue->set_message( $ex->getMessage() )->set_status( QueueStatus::FAILED );
                $queue_repository->update( $queue );
                
                error_log( sprintf( 'Directorist Pricing Plans: Error while assigning plans to listing owner %d: %s', $user_id, $ex->getMessage() ) );
                continue;
            }
        }

        $queue_repository->delete_by_id( $queue->get_id() );

        return $item;
    }

    public function get_directory_plan_assignment( int $directory_type_id, array $assignments ) {
        $assignment = array_values(
            array_filter(
                $assignments, function( $assignment ) use ( $directory_type_id ) {
                    return absint( $assignment['directory_type_id'] ) === absint( $directory_type_id );
                } 
            ) 
        );

        if ( empty( $assignment ) ) {
            throw new Exception( sprintf( 'No assignment found for directory type %d', $directory_type_id ) );
        }

        $assignment = $assignment[0];

        if ( empty( $assignment['plan_id'] ) ) {
            throw new Exception( sprintf( 'No plan id found for directory type %d', $directory_type_id ) );
        }

        if ( empty( $assignment['order_status'] ) ) {
            throw new Exception( sprintf( 'No order status found for directory type %d', $directory_type_id ) );
        }

        return $assignment;
    }

    public function create_order( int $user_id, stdClass $plan, string $order_status, string $currency ) {
        /**
         * @var PlanRepository
         */
        $plan_repository = directorist_pricing_plans_singleton( PlanRepository::class );
        $order_dto       = ( new OrderDTO )->set_user_id( $user_id )->set_ref( $plan->id )->set_ref_type( OrderRefType::PRICING_PLAN )->set_currency( $currency )->set_status( $order_status );

        $order_dto->set_amount( $plan->price )->set_sub_total( $plan->price );

        if ( $plan->is_taxable ) {
            $order_dto->set_tax_type( $plan->tax_type )->set_tax_rate( $plan->tax_rate );
        }

        if ( 0 === $plan->is_allowed_unlimited_listings ) {
            $plan_repository->make_exceeding_listings_as_private( $user_id, $plan->directory_type_id, $plan->allowed_listings );
        }

        if ( 0 === $plan->is_allowed_unlimited_featured_listings ) {
            $plan_repository->make_exceeding_featured_listings_as_regular( $user_id, $plan->directory_type_id, $plan->allowed_featured_listings );
        }

        if ( $plan->fee_type !== PlanFeeType::FREE && $order_status !== OrderStatus::PAID ) {
            $plan_repository->make_listings_pending( $user_id, $plan->directory_type_id );
        }
        
        return directorist_order_repository()->create( $order_dto );
    }

    public function activate_package( int $user_id, stdClass $plan, string $order_id ) {
        $user_package_activation_dto = ( new UserPackageActivationDTO )
            ->set_user_id( $user_id )
            ->set_plan( $plan )
            ->set_order_id( $order_id );

        directorist_pricing_plan_activate_package( $user_package_activation_dto );
    }

    public function get_current_queue( array $item ): ?QueueDTO {
        if ( empty( $item['assignments'] ) || ! is_array( $item['assignments'] ) ) {
            return null;
        }

        $user = directorist_pricing_plans_singleton( PlanRepository::class )->get_listing_owner_without_plans();

        if ( ! $user ) {
            return null;
        }

        $task_data = [
            'directory_type_ids' => explode( ',', $user->directory_type_ids ),
            'assignments'        => $item['assignments'],
        ];

        $dto = ( new QueueDTO )
            ->set_task_id( $user->post_author )
            ->set_task_type( $this->action )
            ->set_task_data( $task_data )
            ->set_status( QueueStatus::IN_PROGRESS );

        $queue_repository = directorist_pricing_plans_singleton( QueueRepository::class );
        $existing_queue   = $queue_repository->get_query_builder()->where( 'task_id', $user->post_author )->first();

        if ( $existing_queue ) {
            $dto->set_id( $existing_queue->id );
            $queue_repository->update( $dto );

            return $dto;
        }

        $queue_id = $queue_repository->create( $dto );
        $dto->set_id( $queue_id );

        return $dto;
    }

    public function get_total_failed_queues(): int {
        $queue_repository = directorist_pricing_plans_singleton( QueueRepository::class );
        
        return $queue_repository->get_query_builder()
            ->where( 'status', QueueStatus::FAILED )
            ->where( 'task_type', $this->action )
            ->count();
    }

    public function clear_failed_queues() {
        $queue_repository = directorist_pricing_plans_singleton( QueueRepository::class );
        
        $queue_repository->get_query_builder()
            ->where( 'status', QueueStatus::FAILED )
            ->where( 'task_type', $this->action )
            ->delete();
    }

    public function dispatch_queue( array $assignments ) {
        if ( empty( $assignments ) || ! is_array( $assignments ) ) {
            return;
        }
        
        $this->before_dispatch();
        $this->push_to_queue( [ 'assignments' => $assignments ] )->save()->dispatch();
    }
}