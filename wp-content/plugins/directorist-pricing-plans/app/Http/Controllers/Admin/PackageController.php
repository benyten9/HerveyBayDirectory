<?php

namespace DirectoristPricingPlan\App\Http\Controllers\Admin;

defined( "ABSPATH" ) || exit;

use WP_REST_Request;
use Directorist\Enums\Order\Status as OrderStatus;
use DirectoristPricingPlan\WpMVC\Routing\Response;
use DirectoristPricingPlan\WpMVC\Exceptions\Exception;
use DirectoristPricingPlan\WpMVC\RequestValidator\Validator;
use DirectoristPricingPlan\App\Http\Controllers\Controller;
use DirectoristPricingPlan\App\Repositories\UserPackageRepository;
use DirectoristPricingPlan\App\DTO\UserPackage\Read;
use DirectoristPricingPlan\App\Enums\Plan\FeeType as PlanFeeType;
use DirectoristPricingPlan\App\DTO\PackageOrder\Read as PackageOrderRead;
use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;
use DirectoristPricingPlan\App\Enums\Plan\Interval as PlanInterval;
use DirectoristPricingPlan\App\Jobs\UnassignedPlanOrderQueue;
use DirectoristPricingPlan\App\Models\Plan;

class PackageController extends Controller {
    public UserPackageRepository $package_repository;

    public function __construct( UserPackageRepository $user_package_repository ) {
        $this->package_repository = $user_package_repository;
    }

    public function index( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate(
            [
                'page'              => 'numeric',
                'per_page'          => 'numeric',
                'search'            => 'string',
                'directory_type_id' => 'numeric',
                'is_recurring'      => 'accepted:0,1',
            ] 
        );

        $dto = ( new Read )
            ->set_page( $request->has_param( 'page' ) ? (int) $request->get_param( 'page' ) : 1 )
            ->set_per_page( $request->has_param( 'per_page' ) ? (int) $request->get_param( 'per_page' ) : 10 )
            ->set_search( $request->has_param( 'search' ) ? $request->get_param( 'search' ) : null )
            ->set_directory_type_id( $request->has_param( 'directory_type_id' ) ? (int) $request->get_param( 'directory_type_id' ) : null )
            ->set_with_usage_data( true );

        return Response::send( $this->package_repository->get( $dto ) );
    }

    public function assignment_options( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate(
            [
                'user_search' => 'string',
            ]
        );

        $user_search = trim( (string) $request->get_param( 'user_search' ) );
        $user_args   = [
            'number'  => -1,
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'fields'  => [ 'ID', 'display_name', 'user_email', 'user_login' ],
        ];

        if ( '' !== $user_search ) {
            $user_args['search']         = '*' . $user_search . '*';
            $user_args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
        }

        $users = array_map(
            function( $user ) {
                $label = $user->display_name ?: $user->user_login;

                if ( ! empty( $user->user_email ) ) {
                    $label .= ' (' . $user->user_email . ')';
                }

                return [
                    'value' => (int) $user->ID,
                    'label' => $label,
                    'email' => $user->user_email,
                ];
            },
            get_users( $user_args )
        );

        $directories = directorist_get_directories(
            [
                'hide_empty'   => false,
                'default_only' => false,
            ]
        );

        $directories = array_map(
            function( $directory ) {
                $plans = Plan::query()
                    ->select( 'id', 'title', 'directory_type_id', 'fee_type', 'type', 'is_published' )
                    ->where( 'directory_type_id', $directory->term_id )
                    ->where( 'is_published', 1 )
                    ->order_by( 'title', 'asc' )
                    ->get();

                return [
                    'value' => (int) $directory->term_id,
                    'label' => $directory->name,
                    'plans' => array_map(
                        function( $plan ) {
                            return [
                                'value'             => (int) $plan->id,
                                'label'             => $plan->title,
                                'directory_type_id' => (int) $plan->directory_type_id,
                                'fee_type'          => $plan->fee_type,
                                'type'              => $plan->type,
                            ];
                        },
                        $plans
                    ),
                ];
            },
            $directories
        );

        return Response::send(
            [
                'users'        => $users,
                'directories'  => array_values( $directories ),
                'order_status' => [
                    [ 'value' => OrderStatus::PAID, 'label' => esc_html__( 'Paid', 'directorist-pricing-plans' ) ],
                    [ 'value' => OrderStatus::PENDING, 'label' => esc_html__( 'Pending', 'directorist-pricing-plans' ) ],
                ],
            ]
        );
    }

    public function assign( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate(
            [
                'user_id'           => 'required|numeric',
                'directory_type_id' => 'required|numeric',
                'plan_id'           => 'required|numeric',
                'order_status'      => 'required|string',
            ]
        );

        $user_id           = absint( $request->get_param( 'user_id' ) );
        $directory_type_id = absint( $request->get_param( 'directory_type_id' ) );
        $plan_id           = absint( $request->get_param( 'plan_id' ) );
        $order_status      = (string) $request->get_param( 'order_status' );

        if ( ! get_userdata( $user_id ) ) {
            throw new Exception( esc_html__( 'Invalid user selected.', 'directorist-pricing-plans' ), 400 );
        }

        if ( ! in_array( $order_status, [ OrderStatus::PAID, OrderStatus::PENDING ], true ) ) {
            throw new Exception( esc_html__( 'Invalid order status selected.', 'directorist-pricing-plans' ), 400 );
        }

        $plan = directorist_get_pricing_plan_by_id( $plan_id );

        if ( ! $plan ) {
            throw new Exception( esc_html__( 'Invalid plan selected.', 'directorist-pricing-plans' ), 400 );
        }

        if ( (int) $plan->directory_type_id !== $directory_type_id ) {
            throw new Exception( esc_html__( 'The selected plan does not belong to the selected directory type.', 'directorist-pricing-plans' ), 400 );
        }

        if ( PlanFeeType::FREE === $plan->fee_type && OrderStatus::PENDING === $order_status ) {
            throw new Exception( esc_html__( 'Free plans cannot be assigned with a pending order status.', 'directorist-pricing-plans' ), 400 );
        }

        if ( $this->package_repository->count_active_packages_for_directory( $user_id, $directory_type_id ) > 0 ) {
            throw new Exception( esc_html__( 'This user already has an active plan in the selected directory type.', 'directorist-pricing-plans' ), 400 );
        }

        if ( directorist_user_has_pending_order( $user_id, $directory_type_id ) ) {
            throw new Exception( esc_html__( 'This user already has a pending order in the selected directory type.', 'directorist-pricing-plans' ), 400 );
        }

        $order_status = PlanFeeType::FREE === $plan->fee_type ? OrderStatus::PAID : $order_status;

        /** @var UnassignedPlanOrderQueue $assignment */
        $assignment = directorist_pricing_plans_singleton( UnassignedPlanOrderQueue::class );
        $order_id   = $assignment->create_order( $user_id, $plan, $order_status, atbdp_get_payment_currency() );

        if ( ! $order_id ) {
            throw new Exception( esc_html__( 'Failed to create order for the selected plan.', 'directorist-pricing-plans' ), 400 );
        }

        if ( OrderStatus::PAID === $order_status ) {
            $assignment->activate_package( $user_id, $plan, $order_id );
        }

        return Response::send(
            [
                'message' => OrderStatus::PAID === $order_status
                    ? esc_html__( 'Plan was assigned successfully.', 'directorist-pricing-plans' )
                    : esc_html__( 'Pending order was created successfully.', 'directorist-pricing-plans' ),
                'data'    => [
                    'order_id' => (int) $order_id,
                ],
            ],
            201
        );
    }

    public function show( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate(
            [
                "id" => "required|numeric"
            ]
        );

        $item = $this->package_repository->single( $request->get_param( "id" ) );

        if ( ! $item ) {
            throw new Exception( esc_html__( "Package not found.", 'directorist-pricing-plans' ) );
        }

        return Response::send(
            [
                "data" => $item
            ]
        );
    }

    public function orders( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate(
            [
                "id"       => "required|numeric",
                "page"     => "numeric",
                "per_page" => "numeric",
                "search"   => "string"
            ]
        );

        $dto = ( new PackageOrderRead() )
            ->set_package_id( (int) $request->get_param( "id" ) )
            ->set_page( (int) $request->get_param( "page" ) )
            ->set_per_page( (int) $request->get_param( "per_page" ) )
            ->set_search( (string) $request->get_param( "search" ) );

        return Response::send( $this->package_repository->get_orders( $dto ) );
    }

    public function cancel( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate(
            [
                "id" => "required|numeric"
            ]
        );

        $package = $this->package_repository->get_by_id( $request->get_param( "id" ) );

        if ( ! $package ) {
            throw new Exception( esc_html__( "Package not found.", 'directorist-pricing-plans' ) );
        }

        // If recurring cancel via subscription gateway
        if ( (int) $package->is_recurring === 1 ) {
            $is_cancelled = apply_filters( "directorist_pricing_plan_subscription_canceled", true, $this->package_repository->to_dto( $package ), 'admin' );
            
            if ( ! $is_cancelled ) {
                throw new Exception( esc_html__( "Package was not cancelled, please try again.", 'directorist-pricing-plans' ) );
            }
        }

        $this->package_repository->cancel_package( $package->id, 'admin' );

        return Response::send(
            [
                "message" => esc_html__( "Package was cancelled.", 'directorist-pricing-plans' )
            ]
        );
    }

    public function cancel_at_period_end( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate(
            [
                "id" => "required|numeric"
            ]
        );

        $package = $this->package_repository->get_by_id( $request->get_param( "id" ) );

        if ( ! $package ) {
            throw new Exception( esc_html__( "Package not found.", 'directorist-pricing-plans' ) );
        }

        $plan      = $package->plan_id ? Plan::query()->where( 'id', $package->plan_id )->first() : null;
        $plan_type = $plan->type ?? PlanType::PACKAGE;

        if ( PlanType::PAY_PER_LISTING === $plan_type ) {
            throw new Exception( esc_html__( "Cancel at period end is not applicable for pay per listing packages.", 'directorist-pricing-plans' ) );
        }

        if ( PlanType::PACKAGE === $plan_type && PlanInterval::LIFETIME === $plan->interval_type ) {
            throw new Exception( esc_html__( "Cancel at period end is not applicable for lifetime packages.", 'directorist-pricing-plans' ) );
        }

        // If recurring cancel via subscription gateway
        if ( (int) $package->is_recurring === 1 ) {
            $is_cancelled = apply_filters( "directorist_pricing_plan_subscription_canceled_at_period_end", true, $this->package_repository->to_dto( $package ), 'admin' );

            if ( ! $is_cancelled ) {
                throw new Exception( esc_html__( "Package was not cancelled, please try again.", 'directorist-pricing-plans' ) );
            }
        }

        $this->package_repository->cancel_package_at_period_end( $package->id, 'admin' );

        return Response::send(
            [
                "message" => esc_html__( "Package was scheduled for cancellation.", 'directorist-pricing-plans' )
            ]
        );
    }

    public function logs( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate(
            [
                "id" => "required|numeric",
            ]
        );

        $package = $this->package_repository->get_by_id( $request->get_param( "id" ) );

        if ( ! $package ) {
            throw new Exception( esc_html__( "Package not found.", 'directorist-pricing-plans' ) );
        }

        return Response::send( directorist_subscription_log_repository()->get_by_package_id( $request->get_param( "id" ) ) );
    }
}
