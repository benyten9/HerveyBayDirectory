<?php

namespace DirectoristPricingPlan\App\Providers;

defined( "ABSPATH" ) || exit;

use stdClass;
use WP_REST_Request;

use Directorist\Utils\RequestValidator as DirectoristValidator;
use Directorist\Utils\Database\Query\Builder as OrderQueryBuilder;
use Directorist\Utils\Mime;
use Directorist\Contracts\PaymentInterface;
use Directorist\DTO\Order\DTO as OrderDTO;
use Directorist\Enums\Order\Status;
use Directorist\Helpers\DateTime;

use DirectoristPricingPlan\App\DTO\PlanOrderMeta\DTO as PlanOrderMetaDTO;
use DirectoristPricingPlan\App\DTO\Proration\Result as ProrationResult;
use DirectoristPricingPlan\App\DTO\UserPackage\Activation as UserPackageActivationDTO;
use DirectoristPricingPlan\App\Enums\Plan\FeeType;
use DirectoristPricingPlan\App\Enums\Order\RefType as OrderRefType;
use DirectoristPricingPlan\App\Enums\Plan\Interval as PlanInterval;
use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;
use DirectoristPricingPlan\App\Models\Plan;
use DirectoristPricingPlan\App\Repositories\Admin\PlanRepository;
use DirectoristPricingPlan\App\Repositories\PlanOrderMetaRepository;
use DirectoristPricingPlan\App\Repositories\UsesRepository;
use DirectoristPricingPlan\App\Utils\PlanProration;
use DirectoristPricingPlan\WpMVC\View\View;
use DirectoristPricingPlan\WpMVC\Contracts\Provider;
use DirectoristPricingPlan\WpMVC\Exceptions\Exception;

class CheckoutServiceProvider implements Provider {
    const CHECKOUT_TYPE = 'plan';

    /** @var ProrationResult|null Cached proration result for the current request. */
    private ?ProrationResult $proration_result = null;

    public function boot() {
        add_filter( 'directorist_checkout_process_payment', [$this, 'checkout_process_payment'], 10, 3 );
        add_filter( 'directorist_checkout_types', [$this, 'add_checkout_type'] );
        add_action( 'directorist_checkout_validation', [$this, 'validate_checkout'], 10, 2 );
        add_action( 'directorist_checkout_validate_payment_processor', [ $this, 'validate_payment_processor' ], 10, 4 );
        add_filter( 'directorist_checkout_table', [$this, 'handle_checkout_table'], 5, 4 );
        add_filter( 'directorist_checkout_subtotal', [$this, 'handle_checkout_subtotal'], 10, 3 );
        add_filter( 'directorist_checkout_total', [$this, 'handle_checkout_total'], 10, 3 );
        add_action( 'directorist_checkout_create_order', [$this, 'handle_checkout_create_order'], 10, 3 );
        add_action( 'directorist_after_order_update', [$this, 'activate_package_after_order_updated'], 10, 1 );
        add_action( 'directorist_after_order_update', [$this, 'cancel_package_after_order_unpaid'], 10, 1 );
        add_filter( 'directorist_payment_receipt_order_items', [$this, 'handle_payment_receipt_order_items'], 10, 2 );
        add_filter( 'directorist_order_list_query', [$this, 'add_plan_to_order_query'], 10, 2 );
        add_filter( 'directorist_order_data', [$this, 'handle_order_data'], 10, 1 );
        add_filter( 'directorist_checkout_product_name', [$this, 'handle_checkout_product_name'], 10, 2 );
        add_filter( 'directorist_ajax_listing_submission_response', [ $this, 'handle_listing_submission_response_data' ], 10, 2 );
        add_filter( 'directorist_listing_update_args_after_preview', [ $this, 'handle_listing_status_after_preview' ], 10, 1 );
        add_action( 'atbdp_before_checkout_form_end', [ $this, 'before_checkout_form_end' ], 10 );
        add_filter( 'directorist_checkout_active_gateways', [ $this, 'handle_active_gateways' ], 40, 2 );
        add_filter( 'directorist_rest_legacy_order_valid_plan', [ $this, 'validate_legacy_order_plan' ], 10, 2 );
        add_filter( 'directorist_rest_legacy_order_data', [ $this, 'handle_legacy_order_data' ], 10, 2 );
        add_filter( 'directorist_rest_legacy_order_remaining_listings', [ $this, 'handle_legacy_order_remaining_listings' ], 10, 2 );
        add_filter( 'directorist_rest_legacy_order_remaining_featured_listings', [ $this, 'handle_legacy_order_remaining_featured_listings' ], 10, 2 );
    }

    public function checkout_process_payment( bool $process_payment, OrderDTO $order_dto, WP_REST_Request $request ) {
        if ( self::CHECKOUT_TYPE !== $request->get_param( 'checkout_type' ) ) {
            return $process_payment;
        }

        if ( ! $order_dto->is_initialized( 'ref_type' ) || ! $order_dto->is_initialized( 'ref' ) ) {
            return $process_payment;
        }

        if ( $order_dto->get_ref_type() !== OrderRefType::PRICING_PLAN || empty( $order_dto->get_ref() ) ) {
            return $process_payment;
        }

        $plan = directorist_get_pricing_plan_by_id( (int) $order_dto->get_ref() );

        if ( ! $plan ) {
            return $process_payment;
        }

        if ( directorist_plan_has_subscription( $plan ) ) {
            return true;
        }

        return $process_payment;
    }

    public function validate_payment_processor( PaymentInterface $payment_processor, OrderDTO $order_dto, string $checkout_type, WP_REST_Request $request ) {
        if ( $checkout_type !== self::CHECKOUT_TYPE ) {
            return;
        }

        $plan = $this->get_plan_by_id( $request->get_param( 'plan_id' ) );

        if ( ! $plan ) {
            return;
        }

        if ( ! directorist_plan_has_subscription( $plan ) ) {
            return;
        }
        
        if ( ! $payment_processor->supports_recurring() ) {
            throw new Exception( __( 'This payment processor does not support recurring payments.', 'directorist-pricing-plans' ), 400 );
        }
    }

    public function handle_listing_submission_response_data( array $data, array $request ) {
        if ( ! empty( $data['edited_listing'] ) ) {
            return $data;
        }

        $listing_id          = (int) $data['id'];
        $is_featured_listing = isset( $request['listing_type'] ) && 'featured' === $request['listing_type'];
        $directory_type_id   = directorist_get_listings_directory_type( $listing_id );

        if ( ! $directory_type_id ) {
            throw new \Exception( __( 'Invalid directory selected.', 'directorist-pricing-plans' ) );
        }

        $selected_plan_id       = isset( $request['plan_id'] ) ? (int) $request['plan_id'] : null;
        $current_active_package = null;

        $active_packages = directorist_user_package_repository()->get_active_packages_for_directory(
            get_current_user_id(),
            $directory_type_id
        );

        foreach ( $active_packages as $active_pkg ) {
            if ( $selected_plan_id && (int) $active_pkg->plan_id === $selected_plan_id ) {
                $current_active_package = $active_pkg;
                break;
            }
        }

        if ( \count( $active_packages ) > 1 && ! $current_active_package ) {
            throw new \Exception( __( 'You already have multiple active plans and cannot purchase or activate another one at this time.', 'directorist-pricing-plans' ) );
        }

        if ( \count( $active_packages ) === 1 ) {
            $current_active_package = $active_packages[0]; 
        }

        // Validate if the plan id is provided or the user has an active package
        if ( ! $selected_plan_id && ! $current_active_package ) {
            throw new \Exception( __( 'A plan is required to submit listing.', 'directorist-pricing-plans' ) );
        }

        $selected_plan = null;
        $active_plan   = null;

        if ( $selected_plan_id ) {
            $selected_plan = directorist_get_pricing_plan_by_id( $selected_plan_id );

            if ( $current_active_package && (int) $current_active_package->plan_id === $selected_plan_id ) {
                $active_plan = $selected_plan;
            }
        }

        if ( $current_active_package && ! $active_plan ) {
            $active_plan = directorist_get_pricing_plan_by_id( (int) $current_active_package->plan_id );
        }

        if ( ! $selected_plan && ! $active_plan ) {
            throw new \Exception( __( 'Invalid plan id.', 'directorist-pricing-plans' ) );
        }

        // Prevent reuse of free one-time plans
        $this->validate_one_time_plan_usage( $selected_plan, $active_plan );

        $current_plan = $selected_plan ?? $active_plan;

        if ( ! directorist_plan_has_listing_quota( $current_plan ) ) {
            throw new \Exception( directorist_plan_no_listing_quota_message() );
        }

        if ( $active_plan && (int) $active_plan->id === (int) $current_plan->id ) {
            // For package plans, validate the listing can be approved under the current active package before allowing submission,
            if ( PlanType::PACKAGE === $current_plan->type ) {
                try {
                    do_action( 'directorist_validate_listing_plan_approval', $directory_type_id, $listing_id, $is_featured_listing, $current_active_package );

                    if ( ! empty( $current_active_package->is_legacy ) ) {
                        update_post_meta( $listing_id, directorist_plan_key(), $current_active_package->plan_id );
                    }

                    return $data;
                } catch ( \Exception $e ) {
                    throw new \Exception( $e->getMessage() );
                }
            }
        }

        if ( ! $active_plan && PlanType::PACKAGE === $selected_plan->type ) {
            // Package plans do not allow multiple pending orders
            if ( directorist_current_user_has_pending_order( $directory_type_id ) ) {
                throw new \Exception( __( 'You have a pending order. Please wait for the payment to be completed.', 'directorist-pricing-plans' ) );
            }

            // If the plan is free then assign the plan to the listing
            if ( $selected_plan->fee_type === FeeType::FREE ) {
                // Create a new order
                $order_repository = directorist_order_repository();
                $order_id         = $order_repository->create(
                    ( new OrderDTO() )
                        ->set_user_id( get_current_user_id() )
                        ->set_listing_id( $listing_id )
                        ->set_is_featured_listing( $is_featured_listing )
                        ->set_ref( (string) $selected_plan->id )
                        ->set_ref_type( OrderRefType::PRICING_PLAN )
                        ->set_currency( atbdp_get_payment_currency() )
                        ->set_amount( $selected_plan->price )
                        ->set_sub_total( $selected_plan->price )
                );

                $this->plan_order_meta_repository()->upsert_by_order_id(
                    $this->build_plan_order_meta_dto( $order_id, $selected_plan )
                );

                $order_repository->update( ( new OrderDTO() )->set_id( $order_id )->set_status( Status::PAID ) );

                return $data;
            }
        }

        // For direct purchase compatibility
        if ( $current_active_package && PlanType::PAY_PER_LISTING === $current_plan->type ) {
            $paid_order = directorist_get_paid_order_without_listing( $current_active_package->plan_id );

            if ( $paid_order ) {
                directorist_order_repository()
                    ->get_query_builder()
                    ->where( 'id', $paid_order->id )
                    ->update(
                        [
                            'listing_id'          => $listing_id,
                            'is_featured_listing' => $current_active_package->is_plan_featured,
                        ]
                    );

                if ( $current_active_package->is_plan_featured ) {
                    directorist_set_listing_featured( $listing_id );
                }

                if ( ! empty( $current_active_package->is_legacy ) ) {
                    update_post_meta( $listing_id, directorist_plan_key(), $current_active_package->plan_id );
                }

                return $data;
            }
        }

        // Set the listing status to pending if it is published
        if ( get_post_status( $listing_id ) === 'publish' ) {
            directorist_set_listing_status( $listing_id, 'pending' );
        }
        
        // Redirect to the checkout page
        $data['need_payment'] = true;
        $data['redirect_url'] = directorist_get_checkout_page_url(
            'plan', 
            [
                'plan_id'     => $current_plan->id,
                'listing_id'  => $data['id'],
                'is_featured' => $is_featured_listing ? '1' : '0',
            ]
        );
        
        return $data;
    }

    public function handle_listing_status_after_preview( array $args ): array {
        $listing_id = isset( $args['ID'] ) ? (int) $args['ID'] : 0;

        if ( ! $listing_id || $this->listing_has_active_package( $listing_id ) ) {
            return $args;
        }

        $args['post_status'] = 'pending';

        return $args;
    }

    private function listing_has_active_package( int $listing_id ): bool {
        return directorist_get_listing_package( $listing_id ) ? true : false;
    }

    public function validate_one_time_plan_usage( ?stdClass $selected_plan, ?stdClass $active_plan ) {
        if ( ! $selected_plan ) {
            return;
        }

        if ( ( ! $active_plan || $active_plan && $active_plan->id !== $selected_plan->id ) && $selected_plan->fee_type === FeeType::FREE && $selected_plan->interval_type !== PlanInterval::LIFETIME ) {
            $has_ever_used = directorist_user_package_repository()->has_ever_used_plan( get_current_user_id(), (int) $selected_plan->id );

            if ( $has_ever_used ) {
                throw new \Exception( __( 'This free plan has already been used and cannot be activated again.', 'directorist-pricing-plans' ) );
            }
        }
    }

    public function add_checkout_type( array $checkout_types ) {
        $checkout_types[] = self::CHECKOUT_TYPE;
        return $checkout_types;
    }

    public function validate_checkout( string $checkout_type, WP_REST_Request $request ) {
        if ( $checkout_type !== self::CHECKOUT_TYPE ) return;

        $validator = new DirectoristValidator( $request, new Mime );

        $validation_rules = [
            'plan_id'    => 'required|numeric',
            'listing_id' => 'numeric',
        ];

        if ( $request->has_param( 'is_featured' ) ) {
            $validation_rules['is_featured'] = 'numeric|accepted:0,1';
        }

        $errors = $validator->validate( $validation_rules, false );

        if ( ! empty( $errors ) ) {
            throw new \Exception( array_values( $errors )[0][0], 422 );
        }

        $plan_repository = directorist_pricing_plans_singleton( PlanRepository::class );
        $plan            = $plan_repository->get_by_id( $request->get_param( 'plan_id' ) );

        if ( ! $plan ) {
            throw new \Exception( __( 'Invalid plan id.', 'directorist-pricing-plans' ) );
        }

        if ( ! directorist_plan_has_listing_quota( $plan ) ) {
            throw new \Exception( directorist_plan_no_listing_quota_message(), 400 );
        }

        // Block checkout for users who already have multiple active packages for this directory,
        // unless the selected plan is already among their active packages (renewal / upgrade).
        $active_packages = directorist_user_package_repository()->get_active_packages_for_directory(
            get_current_user_id(),
            (int) $plan->directory_type_id
        );

        $active_package          = null;
        $selected_active_package = null;
        
        foreach ( $active_packages as $active_pkg ) {
            if ( (int) $active_pkg->plan_id === (int) $plan->id ) {
                $selected_active_package = $active_pkg;
                break;
            }
        }

        if ( count( $active_packages ) === 1 ) {
            $active_package = $active_packages[0];

            // Prevent if user is switching away from an active recurring subscription
            if ( (int) $active_package->is_recurring === 1 && (int) $plan->id !== (int) $active_package->plan_id ) {
                throw new Exception( __( 'You must cancel your existing subscription before switching plans.', 'directorist-pricing-plans' ) );
            }
        } else if ( count( $active_packages ) > 1 ) {
            if ( ! $selected_active_package ) {
                throw new \Exception( __( 'You already have multiple active plans and cannot purchase or activate another one at this time.', 'directorist-pricing-plans' ) );
            }

            $active_package = $selected_active_package;
        }

        // Prevent reuse of free one-time plans
        $this->validate_one_time_plan_usage( $plan, $active_package );

        $selected_plan_type       = $plan->type;
        $active_package_plan_type = null;

        if ( $active_package ) {
            $active_package_plan_type = $active_package->plan_type;
            
            if ( $active_package_plan_type === PlanType::PAY_PER_LISTING && $selected_plan_type === PlanType::PAY_PER_LISTING && (int) $active_package->plan_id !== (int) $plan->id ) {
                throw new \Exception( __( 'You are not allowed to switch plans while on the Pay-Per-Listing plan. Please cancel your current package before switching to a different plan.', 'directorist-pricing-plans' ) );
            }

            if ( ( $selected_plan_type === PlanType::PACKAGE && $active_package->plan_type !== PlanType::PACKAGE ) ) {
                throw new \Exception( __( 'You are not allowed to switch to the Pay-Per-Listing plan from your current package. Please cancel your existing package or wait until it expires before switching to another plan.', 'directorist-pricing-plans' ) );
            }
        }

        // Pay per listing plans allow multiple pending orders
        if ( PlanType::PAY_PER_LISTING !== $active_package_plan_type && directorist_current_user_has_pending_order( $plan->directory_type_id ) ) {
            throw new \Exception( __( 'You have a pending order. Please wait for the payment to be completed.', 'directorist-pricing-plans' ) );
        }

        $is_featured_listing = '1' === strval( $request->get_param( 'is_featured' ) );

        // Quota check only applies to package plans
        if ( PlanType::PAY_PER_LISTING !== $active_package_plan_type && ! apply_filters( 'directorist_has_plan_remaining_quota', true, $plan, $is_featured_listing ) ) {
            throw new \Exception( __( 'You have reached the maximum number of allowed listings.', 'directorist-pricing-plans' ) );
        }

        // Proration only applies to package plans
        if ( PlanType::PAY_PER_LISTING !== $active_package_plan_type ) {
            $proration_result = $this->get_proration_result( $request );

            if ( ! $proration_result->is_allowed() ) {
                throw new \Exception( $proration_result->get_error_message() );
            }
        }
    }

    public function handle_checkout_table( string $checkout_type, float $total, float $subtotal, WP_REST_Request $request ) {
        if ( $checkout_type !== self::CHECKOUT_TYPE ) return;

        $plan_repository = directorist_pricing_plans_singleton( PlanRepository::class );
        $plan            = $plan_repository->get_by_id( $request->get_param( 'plan_id' ) );

        if ( ! $plan ) {
            throw new Exception( __( 'Invalid plan id.', 'directorist-pricing-plans' ) );
        }

        $plan_dto   = $plan_repository->to_dto( $plan );
        $tax_amount = directorist_compute_fixed_or_percent_amount( $plan_dto->get_tax_type(), $plan_dto->get_tax_rate(), $subtotal );

        View::render(
            'plan-summary', apply_filters(
                'directorist_checkout_plan_summary_data', [
                    'plan'             => $plan_dto,
                    'plan_data'        => $plan,
                    'subtotal'         => $subtotal,
                    'tax_amount'       => $tax_amount,
                    'total'            => $total,
                    'proration_result' => $this->get_proration_result( $request ),
                    'request'          => $request,
                ] 
            )
        );
    }

    public function handle_checkout_subtotal( float $subtotal, string $checkout_type, WP_REST_Request $request ) {
        if ( $checkout_type !== self::CHECKOUT_TYPE ) return $subtotal;

        $plan_repository = directorist_pricing_plans_singleton( PlanRepository::class );
        $plan            = $plan_repository->get_by_id( $request->get_param( 'plan_id' ) );

        if ( ! $plan ) {
            throw new Exception( __( 'Invalid plan id.', 'directorist-pricing-plans' ) );
        }

        return $this->get_proration_result( $request )->get_adjusted_price();
    }

    public function handle_checkout_total( float $total, string $checkout_type, WP_REST_Request $request ) {
        if ( $total <= 0 ) {
            return $total;
        }

        if ( $checkout_type !== self::CHECKOUT_TYPE ) {
            return $total;
        }

        $plan_repository = directorist_pricing_plans_singleton( PlanRepository::class );
        $plan            = $plan_repository->get_by_id( $request->get_param( 'plan_id' ) );

        if ( ! $plan ) {
            throw new Exception( __( 'Invalid plan id.', 'directorist-pricing-plans' ) );
        }

        $total += directorist_get_plan_tax_amount( $plan, $total );

        return $total;
    }

    public function handle_checkout_create_order( OrderDTO $dto, string $checkout_type, WP_REST_Request $request ) {
        if ( $checkout_type !== self::CHECKOUT_TYPE ) {
            return;
        }

        $plan = directorist_get_pricing_plan_by_id( $request->get_param( 'plan_id' ) );

        if ( ! $plan ) {
            throw new Exception( __( 'Invalid plan id.', 'directorist-pricing-plans' ) );
        }

        if ( ! directorist_plan_has_listing_quota( $plan ) ) {
            throw new Exception( directorist_plan_no_listing_quota_message(), 400 );
        }

        $plan_type = $plan->type ?? PlanType::PACKAGE;

        // Check for pending orders — pay per listing plans allow multiple pending orders
        if ( PlanType::PAY_PER_LISTING !== $plan_type && directorist_current_user_has_pending_order( $plan->directory_type_id ) ) {
            throw new Exception( __( 'You have a pending order. Please wait for the payment to be completed.', 'directorist-pricing-plans' ), 400 );
        }

        if ( $request->get_param( 'listing_id' ) ) {
            $dto->set_listing_id( $request->get_param( 'listing_id' ) );
        }

        if ( '1' === strval( $request->get_param( 'is_featured' ) ) ) {
            $dto->set_is_featured_listing( true );
        }

        $adjusted_price = $this->get_proration_result( $request )->get_adjusted_price();
        $dto->set_ref( (string) $plan->id )->set_ref_type( OrderRefType::PRICING_PLAN )->set_amount( $adjusted_price )->set_sub_total( $adjusted_price );

        if ( $adjusted_price > 0 && $plan->is_taxable ) {
            $dto->set_tax_type( $plan->tax_type )->set_tax_rate( $plan->tax_rate );
        }

        if ( $request->get_param( 'listing_id' ) && $adjusted_price > 0 ) {
            directorist_set_listing_status( $request->get_param( 'listing_id' ), Status::PENDING );
        }

        $dto   = apply_filters( 'directorist_plan_checkout_create_order', $dto, $request, $plan );
        $order = directorist_order_repository()->create( $dto );

        if ( ! $order ) {
            throw new Exception( __( 'Failed to create order.', 'directorist-pricing-plans' ) );
        }

        $dto->set_id( $order );

        $this->plan_order_meta_repository()->upsert_by_order_id(
            $this->build_checkout_plan_order_meta_dto( $dto->get_id(), $plan, $request )
        );
    }

    private function build_checkout_plan_order_meta_dto( int $order_id, stdClass $plan, WP_REST_Request $request ): PlanOrderMetaDTO {
        $is_trial     = (int) $request->get_param( 'is_trial' ) === 1 && ! empty( $plan->is_trial_enabled ) && (int) $plan->trial_interval_count > 0;
        $is_recurring = directorist_plan_has_subscription( $plan );

        $interval_type       = null;
        $interval_count      = null;
        $current_period_end  = null;
        $proration_result    = $this->get_proration_result( $request );
        $plan_interval_count = $is_trial ? (int) $plan->trial_interval_count : (int) $plan->interval_count;
        $plan_interval_type  = $is_trial ? $plan->trial_interval_type : $plan->interval_type;

        if ( PlanInterval::LIFETIME !== $plan_interval_type && $plan_interval_count > 0 ) {
            $interval_type  = $plan_interval_type;
            $interval_count = $plan_interval_count;
        }

        if ( null !== $proration_result->get_extending_days() ) {
            $interval_type  = PlanInterval::DAY;
            $interval_count = $proration_result->get_extending_days();
            $is_recurring   = false; // Proration extensions are one-time adjustments, not recurring intervals.
        }

        if ( $proration_result->get_override_period_end() ) {
            $current_period_end = $proration_result->get_override_period_end();
            $is_recurring       = false; // When a specific period end date is set due to proration, treat it as a one-time order rather than a recurring subscription, since the next billing date may not be determinable at this point.
        }

        if ( $proration_result->get_adjusted_price() !== (float) $plan->price ) {
            $is_recurring = false; // When proration results in a price adjustment, treat the order as a one-time purchase for the adjusted amount, rather than a recurring subscription, since the future billing amounts may not be determinable at this point.
        }

        $dto = ( new PlanOrderMetaDTO )
            ->set_order_id( $order_id )
            ->set_is_recurring( (bool) $is_recurring )
            ->set_is_trial( (bool) $is_trial )
            ->set_interval_type( $interval_type )
            ->set_interval_count( $interval_count )
            ->set_current_period_end( $current_period_end );

        return apply_filters( 'directorist_pricing_plan_checkout_order_meta_dto', $dto, $order_id, $plan, $request );
    }

    private function build_plan_order_meta_dto( int $order_id, stdClass $plan ): PlanOrderMetaDTO {
        $is_trial     = ! empty( $plan->is_trial_enabled ) && (int) $plan->trial_interval_count > 0;
        $is_recurring = directorist_plan_has_subscription( $plan );

        $interval_type       = null;
        $interval_count      = null;
        $plan_interval_count = $is_trial ? (int) $plan->trial_interval_count : (int) $plan->interval_count;
        $plan_interval_type  = $is_trial ? $plan->trial_interval_type : $plan->interval_type;

        if ( PlanInterval::LIFETIME !== $plan_interval_type && $plan_interval_count > 0 ) {
            $interval_type  = $plan_interval_type;
            $interval_count = $plan_interval_count;
        }

        $dto = ( new PlanOrderMetaDTO )
            ->set_order_id( $order_id )
            ->set_is_recurring( (bool) $is_recurring )
            ->set_is_trial( (bool) $is_trial )
            ->set_interval_type( $interval_type )
            ->set_interval_count( $interval_count );

        return apply_filters( 'directorist_pricing_plan_free_order_meta_dto', $dto, $order_id, $plan );
    }

    public function cancel_package_after_order_unpaid( OrderDTO $order_dto ) {
        if ( Status::PAID === $order_dto->get_status() ) {
            return;
        }

        if ( ! $order_dto->is_initialized( 'ref_type' ) || ! $order_dto->is_initialized( 'ref' ) ) {
            return;
        }

        if ( $order_dto->get_ref_type() !== OrderRefType::PRICING_PLAN || null === $order_dto->get_ref() ) {
            return;
        }

        $user_package_repository = directorist_user_package_repository();
        $package                 = $user_package_repository->get_by_last_order_id( $order_dto->get_id() );

        if ( ! $package ) {
            return;
        }

        $user_package_repository->cancel_package( $package->id );
    }

    public function activate_package_after_order_updated( OrderDTO $order_dto ) {
        if ( Status::PAID !== $order_dto->get_status() ) {
            return;
        }

        if ( ! $order_dto->is_initialized( 'ref_type' ) || ! $order_dto->is_initialized( 'ref' ) ) {
            return;
        }

        if ( $order_dto->get_ref_type() !== OrderRefType::PRICING_PLAN || null === $order_dto->get_ref() ) {
            return;
        }

        $plan = directorist_get_pricing_plan_by_id( (int) $order_dto->get_ref() );

        if ( ! $plan ) {
            return;
        }

        $user_package_repository = directorist_user_package_repository();

        if ( $user_package_repository->has_activated_package_for_order( $order_dto->get_id() ) ) {
            return;
        }

        $old_package = directorist_get_current_package( $plan->directory_type_id, $order_dto->get_user_id() );

        if ( $old_package && (int) $old_package->is_recurring === 1 && (int) $old_package->plan_id !== (int) $plan->id ) {
            throw new Exception( __( 'You must cancel your existing subscription before switching plans.', 'directorist-pricing-plans' ) );
        }

        // For pay per listing plans, if package already exists (active), just handle listing publication
        if ( PlanType::PAY_PER_LISTING === $plan->type ) {
            if ( $old_package && (int) $old_package->plan_id === (int) $plan->id ) {
                $user_package_repository->link_package_order( $old_package->id, $order_dto->get_id() );

                $listing_id = $order_dto->is_initialized( 'listing_id' ) ? $order_dto->get_listing_id() : null;

                // Package already active — just publish the listing
                if ( $listing_id ) {
                    $is_featured_listing = $order_dto->is_initialized( 'is_featured_listing' ) ? $order_dto->get_is_featured_listing() : false;

                    do_action( 'directorist_after_listing_plan_approval', $listing_id, $is_featured_listing );

                    directorist_pricing_plan_apply_plan_listing_expiration( $listing_id, $plan );
                }
                return;
            }
        }

        // Activate the new package
        $user_package_repository->activate_package( $this->build_package_activation_dto_from_order( $order_dto, $plan ) );
    }

    private function build_package_activation_dto_from_order( OrderDTO $order_dto, stdClass $plan ): UserPackageActivationDTO {
        $activation_dto = ( new UserPackageActivationDTO )
            ->set_user_id( $order_dto->get_user_id() )
            ->set_plan( $plan )
            ->set_order_id( $order_dto->get_id() );

        $order_meta = $this->plan_order_meta_repository()->get_by_order_id( $order_dto->get_id() );

        if ( ! $order_meta ) {
            return $activation_dto;
        }

        if ( ! empty( $order_meta->is_trial ) ) {
            $activation_dto->set_is_trial( true );
        }

        $activation_dto->set_is_recurring( ! empty( $order_meta->is_recurring ) );

        if ( null !== $order_meta->interval_type ) {
            $activation_dto->set_interval_type( $order_meta->interval_type );
        }

        if ( null !== $order_meta->interval_count ) {
            $activation_dto->set_interval_count( (int) $order_meta->interval_count );
        }

        if ( ! empty( $order_meta->current_period_end ) ) {
            $activation_dto->set_current_period_end( new DateTime( $order_meta->current_period_end ) );
        }

        return $activation_dto;
    }

    public function handle_payment_receipt_order_items( array $order_items, OrderDTO $order ) {
        if ( ! $order->is_initialized( 'ref_type' ) || ! $order->is_initialized( 'ref' ) ) {
            return $order_items;
        }

        if ( $order->get_ref_type() !== OrderRefType::PRICING_PLAN || null === $order->get_ref() ) {
            return $order_items;
        }

        $plan_repository = directorist_pricing_plans_singleton( PlanRepository::class );
        $plan            = $plan_repository->get_by_id( (int) $order->get_ref() );
        $price           = $order->get_amount();

        $order_meta_repo = directorist_pricing_plans_singleton( PlanOrderMetaRepository::class );
        $order_meta      = $order_meta_repo->get_by_order_id( $order->get_id() );

        if ( $order_meta && (int) $order_meta->is_trial === 1 ) {
            $price = $plan->price;
        }

        $order_items[] = [
            'title' => $plan->title,
            'desc'  => $plan->description,
            'price' => $price,
        ];

        return $order_items;
    }

    public function add_plan_to_order_query( OrderQueryBuilder $query ): OrderQueryBuilder {
        $query->columns[] = 'pricing_plan.title as plan_title';
        
        return $query->left_join(
            Plan::get_table_name() . ' as pricing_plan',
            function( $join ) {
                $join->on_column( 'd_order.ref', '=', 'pricing_plan.id' )
                     ->on( 'd_order.ref_type', '=', OrderRefType::PRICING_PLAN );
            }
        );
    }

    public function handle_order_data( $order ) {
        if ( OrderRefType::PRICING_PLAN !== $order->ref_type ) {
            return $order;
        }

        $order->order_type = ! empty( $order->plan_title )
            ? sprintf( __( 'Pricing Plan - %s', 'directorist-pricing-plans' ), $order->plan_title )
            : __( 'Pricing Plan', 'directorist-pricing-plans' );

        return $order;
    }

    public function validate_legacy_order_plan( bool $valid, int $plan_id ): bool {
        return $valid || (bool) directorist_get_pricing_plan_by_id( $plan_id );
    }

    public function handle_legacy_order_data( array $data, $order ): array {
        if ( OrderRefType::PRICING_PLAN !== ( $order->ref_type ?? '' ) ) {
            return $data;
        }

        $plan = directorist_get_pricing_plan_by_id( (int) $order->ref );

        if ( ! $plan ) {
            return $data;
        }

        $data['plan']      = (int) $plan->id;
        $data['directory'] = (int) $plan->directory_type_id;

        return $data;
    }

    public function handle_legacy_order_remaining_listings( int $remaining, $order ): int {
        return $this->get_legacy_order_remaining_quota( $remaining, $order, false );
    }

    public function handle_legacy_order_remaining_featured_listings( int $remaining, $order ): int {
        return $this->get_legacy_order_remaining_quota( $remaining, $order, true );
    }

    private function get_legacy_order_remaining_quota( int $remaining, $order, bool $is_featured_listing ): int {
        if ( OrderRefType::PRICING_PLAN !== ( $order->ref_type ?? '' ) ) {
            return $remaining;
        }

        $plan = directorist_get_pricing_plan_by_id( (int) $order->ref );

        if ( ! $plan ) {
            return $remaining;
        }

        $usage = directorist_pricing_plans_singleton( UsesRepository::class )
            ->get_listings_uses( (int) $order->user_id, $plan, $is_featured_listing );

        return (int) ( $usage['remaining'] ?? $remaining );
    }

    public function handle_checkout_product_name( string $product_name, OrderDTO $dto ) {
        if ( ! $dto->is_initialized( 'ref_type' ) || ! $dto->is_initialized( 'ref' ) ) {
            return $product_name;
        }
    
        if ( OrderRefType::PRICING_PLAN !== $dto->get_ref_type() || null === $dto->get_ref() ) {
            return $product_name;
        }

        $plan_repository = directorist_pricing_plans_singleton( PlanRepository::class );
        $plan            = $plan_repository->get_by_id( $dto->get_ref() );

        return $plan->title;
    }

    public function before_checkout_form_end() {
        if ( isset( $_GET['directory_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<input type="hidden" name="directory_type" value="' . esc_attr( sanitize_text_field( wp_unslash( $_GET['directory_type'] ) ) ) . '">'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        if ( isset( $_GET['listing_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<input type="hidden" name="listing_id" value="' . esc_attr( absint( $_GET['listing_id'] ) ) . '">'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        if ( isset( $_GET['is_featured'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<input type="hidden" name="is_featured" value="' . esc_attr( absint( $_GET['is_featured'] ) ) . '">'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }
    }

    private function get_plan_by_id( int $plan_id ): ?stdClass {
        $plan_repository = directorist_pricing_plans_singleton( PlanRepository::class );
        $plan            = $plan_repository->get_by_id( $plan_id );

        return $plan;
    }

    private function plan_order_meta_repository(): PlanOrderMetaRepository {
        return directorist_pricing_plans_singleton( PlanOrderMetaRepository::class );
    }

    /**
     * Lazily compute and cache the proration result for the current checkout request.
     */
    private function get_proration_result( WP_REST_Request $request ): ProrationResult {
        if ( null !== $this->proration_result ) {
            return $this->proration_result;
        }

        $new_plan = $this->get_plan_by_id( (int) $request->get_param( 'plan_id' ) );

        if ( ! $new_plan ) {
            $this->proration_result = ProrationResult::allow( 0.0, null, 0.0 );
            return $this->proration_result;
        }

        // Proration does not apply to pay per listing plans
        $new_plan_type = $new_plan->type ?? PlanType::PACKAGE;

        if ( PlanType::PAY_PER_LISTING === $new_plan_type ) {
            $this->proration_result = ProrationResult::allow( (float) $new_plan->price, null, 0.0 );
            return $this->proration_result;
        }

        $current_package = directorist_get_current_package( $new_plan->directory_type_id );

        if ( $current_package && $current_package->is_trial ) {
            return ProrationResult::allow( $new_plan->price, null, 0.0 );
        }

        $current_plan = $current_package ? directorist_get_pricing_plan_by_id( (int) $current_package->plan_id ) : null;

        $plan_proration         = directorist_pricing_plans_singleton( PlanProration::class );
        $this->proration_result = $plan_proration->calculate_result( $current_package, $current_plan, $new_plan );

        return $this->proration_result;
    }

    public function handle_active_gateways( array $active_gateways, string $checkout_type ): array {
        if ( $checkout_type !== self::CHECKOUT_TYPE ) {
            return $active_gateways;
        }

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading GET parameters for display purposes only, not processing form submission
        $plan = $this->get_plan_by_id( isset( $_GET['plan_id'] ) ? absint( wp_unslash( $_GET['plan_id'] ) ) : null );

        // If no plan then show all gateways
        if ( ! $plan ) {
            return $active_gateways;
        }
        
        // If subscription is not enabled, show all gateways
        if ( ! directorist_plan_has_subscription( $plan ) ) {
            return $active_gateways;
        }

        $payment_processors = directorist_get_payment_processors();

        // If no payment processors found, return default active gateways
        if ( empty( $payment_processors ) || ! is_array( $payment_processors ) ) {
            return $active_gateways;
        }

        // If subscription is enabled, hide gateways that do not have subscription support
        foreach ( $active_gateways as $gateway_name ) {
            if ( ! isset( $payment_processors[ $gateway_name ] ) ) {
                continue;
            }

            /**
             * @var PaymentInterface $instance
             */
            $instance = directorist_make( $payment_processors[ $gateway_name ], __( 'Invalid payment gateway.', 'directorist-pricing-plans' ) );

            // If subscription is not enabled, hide the gateway
            if ( apply_filters( 'directorist_pricing_plan_hide_gateway_for_subscription', ! $instance->supports_recurring(), $instance, $plan, $active_gateways, $checkout_type ) ) {
                $index = array_search( $gateway_name, $active_gateways );
                unset( $active_gateways[ $index ] );
            }
        }

        return $active_gateways;
    }
}
