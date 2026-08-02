<?php

namespace DirectoristStripe\App;

defined( "ABSPATH" ) || exit;

use DirectoristPricingPlan\App\Enums\Plan\Interval as PlanIntervalType;
use DirectoristPricingPlan\App\Repositories\PlanOrderMetaRepository;
use DirectoristPricingPlan\App\Enums\Order\RefType as PlanOrderRefType;
use Directorist\Repositories\PaymentRepository;
use Directorist\Contracts\PaymentInterface;
use Directorist\PaymentProcessors\Payment;
use Directorist\Enums\Order\Status as OrderStatus;
use Directorist\DTO\Order\DTO as OrderDTO;
use DirectoristStripe\Stripe\Coupon;
use DirectoristStripe\Stripe\Checkout\Session;
use DirectoristStripe\Stripe\Stripe as StripeSDK;

class Stripe extends Payment implements PaymentInterface {
    public PaymentRepository $payment_repository;

    protected bool $supports_recurring = true;

    protected bool $supports_trial = true;

    public function __construct() {
        $this->payment_repository = new PaymentRepository();
    }

    public static function get_key(): string {
        return 'stripe_gateway';
    }

    public function pay( OrderDTO $dto, array $params = [] ): ?string {
        $stripe_secret_key = directorist_stripe_get_secret_key();

        if ( empty( $stripe_secret_key ) ) {
            throw new \Exception( 'Stripe secret key is not set', 400 );
        }

        StripeSDK::setApiKey( $stripe_secret_key );

        $session_data = [
            'payment_method_types' => ['card'],
            'success_url'          => $this->get_success_url( $dto->get_id() ),
            'cancel_url'           => directorist_payment_failure_url(),
            'line_items'           => [],
            'metadata'             => [ 'order_id' => (string) $dto->get_id() ],
            'client_reference_id'  => (string) $dto->get_id(),
        ];

        $metadata = [ 'order_id' => $dto->get_id() ];

        $is_trial       = false;
        $is_recurring   = false;
        $interval       = null;
        $interval_count = null;
        $plan           = null;
        $trial_days     = null;
        $item_price     = $dto->get_sub_total();
        
        $is_pricing_plan_order = directorist_stripe_is_pricing_plan_active() && $dto->is_initialized( 'ref_type' ) && $dto->is_initialized( 'ref' ) && PlanOrderRefType::PRICING_PLAN === $dto->get_ref_type() && $dto->get_ref();
        
        /**
         * Check if the plan is initialized
         */
        if ( $is_pricing_plan_order ) {
            $plan = directorist_get_pricing_plan_by_id( $dto->get_ref() );

            if ( ! $plan ) {
                throw new \Exception( 'Plan not found', 404 );
            }

            $product_name   = $plan->title;
            $interval       = $plan->interval_type;
            $interval_count = $plan->interval_count;

            // Use stored order meta when available; fall back to plan data only when null.
            $order_meta     = $this->get_plan_order_meta( $dto->get_id() );
            $meta_recurring = $order_meta ? $order_meta->is_recurring : null;

            if ( null !== $meta_recurring ) {
                $is_recurring = (bool) $meta_recurring;
            } else {
                $is_recurring = 1 === (int) $plan->is_subscription_enabled && $plan->interval_type !== PlanIntervalType::LIFETIME && (int) $plan->interval_count > 0;
            }

            if ( $is_recurring && isset( $params['is_trial'] ) && (int) $params['is_trial'] === 1 ) {
                $is_trial = true;
            }
            
            if ( $is_recurring && $order_meta && (int) $order_meta->is_trial === 1 ) {
                $is_trial = true;
            }

            $trial_days = $is_trial ? $this->calculate_trial_days( $plan->trial_interval_type, $plan->trial_interval_count ) : null;

            if ( $is_trial && $trial_days < 1 ) {
                throw new \Exception( 'Trial days must be greater than 0', 400 );
            }

            $item_price = $is_trial ? $plan->price : $item_price;
            $lookup_key = 'directorist_' . $dto->get_ref_type() . '_' . $dto->get_ref() . '_' . $item_price . '_' . $interval . '_' . $interval_count;

            $metadata['plan_id']    = $dto->get_ref();
            $metadata['lookup_key'] = $lookup_key;
        } else {
            $product_name = apply_filters( 'directorist_checkout_product_name', 'Directorist', $dto );
            $lookup_key   = apply_filters( 'directorist_checkout_lookup_key', 'directorist_' . $dto->get_id() . '_' . $item_price, $dto );
        }

        if ( $is_trial ) {
            $metadata['is_trial'] = 1;
        }

        $session_data['metadata'] = array_merge( $session_data['metadata'], $metadata );

        $discount_amount       = $this->calculate_discount_amount( $dto, $item_price );
        $discounted_item_price = max( 0, $item_price - $discount_amount );
        $first_tax_amount      = $this->calculate_tax_amount( $dto, $discounted_item_price );
        $regular_tax_amount    = $this->calculate_tax_amount( $dto, $item_price );
        $first_amount          = $discounted_item_price + $first_tax_amount;
        $regular_amount        = $item_price + $regular_tax_amount;

        // Add tax as a separate line item if tax amount > 0
        if ( $first_tax_amount > 0 || ( $is_recurring && $regular_tax_amount > 0 ) ) {
            $tax_amount     = $is_recurring ? $regular_tax_amount : $first_tax_amount;
            $tax_lookup_key = $this->get_tax_lookup_key( $dto, $tax_amount, $is_recurring, $interval, $interval_count );
            $tax_price      = $this->get_or_create_tax_price( $tax_lookup_key, $tax_amount, $is_recurring, $interval, $interval_count );

            $session_data['line_items'][] = [
                'price'    => $tax_price->id,
                'quantity' => 1,
            ];
        }

        /**
         * If the payment is not recurring, create a payment session and return the session url
         */
        if ( ! $is_recurring ) {
            if ( $first_amount <= 0 ) {
                return $this->redirect_to_payment_receipt_page( $dto, true );
            }

            $session_data['mode'] = 'payment';
            $unit_amount          = $this->to_stripe_amount( $discounted_item_price );
            
            $price_data = [
                'currency'     => directorist_currency(),
                'product_data' => ['name' => $product_name],
                'unit_amount'  => $unit_amount,
            ];
            
            $session_data['line_items'][] = [
                'price_data' => $price_data,
                'quantity'   => 1,
            ];

            $session_data['payment_intent_data'] = ['metadata' => $metadata];

            return Session::create( $session_data )->url;
        }

        /**
         * If the payment is recurring, create a subscription session and return the session url
         */
        $session_data['mode'] = 'subscription';

        /**
         * Check if a price with this lookup_key already exists
         */
        $existing_prices = \DirectoristStripe\Stripe\Price::all(
            [
                'lookup_keys' => [ $lookup_key ],
                'active'      => true,
                'limit'       => 1,
            ]
        );

        if ( ! empty( $existing_prices->data ) ) {
            /**
             * Use the existing price
             */
            $price = $existing_prices->data[0];
        } else {
            /**
             * Create a new price with lookup_key
             */
            $price_data = [
                'currency'     => directorist_currency(),
                'product_data' => [ 'name' => $product_name ],
                'unit_amount'  => $this->to_stripe_amount( $item_price ),
                'recurring'    => ['interval' => $interval, 'interval_count' => $interval_count],
                'lookup_key'   => $lookup_key
            ];

            $price = \DirectoristStripe\Stripe\Price::create( $price_data );
        }

        $session_data['line_items'][] = [
            'price'    => $price->id,
            'quantity' => 1,
        ];

        $session_data['subscription_data'] = [
            'metadata' => $metadata
        ];

        $first_invoice_discount = 0;

        if ( $first_amount < 1 ) {
            $first_invoice_discount = $regular_amount;
        } elseif ( $first_amount < $regular_amount ) {
            $first_invoice_discount = $regular_amount - $first_amount;
        }

        if ( $this->to_stripe_amount( $first_invoice_discount ) > 0 ) {
            $coupon = $this->get_or_create_first_invoice_coupon( $first_invoice_discount );

            $session_data['discounts'] = [
                [
                    'coupon' => $coupon->id,
                ],
            ];
        }

        if ( $is_trial ) {
            $session_data['subscription_data']['trial_period_days'] = $trial_days;
        }

        return Session::create( $session_data )->url;
    }

    private function get_success_url( int $order_id ): string {
        return add_query_arg(
            [
                'order_id'   => $order_id,
                'session_id' => '{CHECKOUT_SESSION_ID}',
            ],
            rest_url( 'directorist-stripe/success' )
        );
    }

    private function redirect_to_payment_receipt_page( OrderDTO $dto, bool $mark_paid = false ): string {
        if ( OrderStatus::PAID !== $dto->get_status() && ( $mark_paid || $dto->get_sub_total() <= 0 ) ) {
            $order_dto = ( new OrderDTO )
                ->set_id( $dto->get_id() )
                ->set_status( OrderStatus::PAID );

            directorist_order_repository()->update( $order_dto );
        }

        return directorist_payment_receipt_page_link( $dto->get_id() );
    }

    private function calculate_trial_days( string $interval_type, int $interval_count ): int {
        switch ( $interval_type ) {
            case 'day':
                return $interval_count;
            case 'week':
                return $interval_count * 7;
            case 'month':
                return $interval_count * 30;
            case 'year':
                return $interval_count * 365;
            default:
                return 0;
        }
    }

    /**
     * Generate lookup key for tax price
     *
     * @param OrderDTO $dto
     * @return string
     */
    private function get_tax_lookup_key( OrderDTO $dto, float $tax_amount, bool $is_recurring, ?string $interval = null, ?int $interval_count = null ): string {
        $tax_type          = $dto->get_tax_type();
        $tax_rate          = $dto->get_tax_rate();
        $tax_stripe_amount = $this->to_stripe_amount( $tax_amount );
        $billing_key       = $is_recurring ? $interval . '_' . $interval_count : 'onetime';

        return 'directorist_tax_' . $tax_type . '_' . $tax_rate . '_' . $tax_stripe_amount . '_' . $billing_key;
    }

    private function calculate_discount_amount( OrderDTO $dto, float $item_price ): float {
        if ( $item_price <= 0 ) {
            return 0;
        }

        if ( ! $dto->is_initialized( 'coupon_discount' ) || ! $dto->is_initialized( 'coupon_discount_type' ) ) {
            return 0;
        }

        $coupon_discount = (float) $dto->get_coupon_discount();

        if ( $coupon_discount < 1 ) {
            return 0;
        }

        if ( 'percent' === $dto->get_coupon_discount_type() ) {
            $discount_amount = ( $item_price * $coupon_discount ) / 100;
        } else {
            $discount_amount = $coupon_discount;
        }

        return max( 0, round( $discount_amount, 2 ) );
    }

    private function calculate_tax_amount( OrderDTO $dto, float $taxable_amount ): float {
        if ( $taxable_amount <= 0 ) {
            return 0;
        }

        if ( ! $dto->is_initialized( 'tax_type' ) || ! $dto->is_initialized( 'tax_rate' ) ) {
            return 0;
        }

        return directorist_compute_fixed_or_percent_amount( $dto->get_tax_type(), $dto->get_tax_rate(), $taxable_amount );
    }

    private function get_or_create_first_invoice_coupon( float $amount_off ) {
        $coupon_id = 'ds_first_' . substr( md5( directorist_currency() . '_' . $this->to_stripe_amount( $amount_off ) ), 0, 24 );

        try {
            return Coupon::retrieve( $coupon_id );
        } catch ( \Exception $e ) {
            return Coupon::create(
                [
                    'id'         => $coupon_id,
                    'amount_off' => $this->to_stripe_amount( $amount_off ),
                    'currency'   => directorist_currency(),
                    'duration'   => 'once',
                    'name'       => 'First invoice discount',
                ]
            );
        }
    }

    private function to_stripe_amount( float $amount ): int {
        return (int) round( $amount * 100 );
    }

    private function get_plan_order_meta( int $order_id ) {
        if ( ! function_exists( 'directorist_pricing_plans_singleton' ) ) {
            return null;
        }

        return directorist_pricing_plans_singleton( PlanOrderMetaRepository::class )->get_by_order_id( $order_id );
    }

    /**
     * Get or create tax price with lookup key
     *
     * @param string $lookup_key
     * @param float $tax_amount
     * @param bool $is_recurring
     * @param string|null $interval
     * @param int|null $interval_count
     * @return \DirectoristStripe\Stripe\Price
     */
    private function get_or_create_tax_price( string $lookup_key, float $tax_amount, bool $is_recurring, ?string $interval = null, ?int $interval_count = null ) {
        // Check if a price with this lookup_key already exists
        $existing_prices = \DirectoristStripe\Stripe\Price::all(
            [
                'lookup_keys' => [ $lookup_key ],
                'active'      => true,
                'limit'       => 1,
            ]
        );

        if ( ! empty( $existing_prices->data ) ) {
            return $existing_prices->data[0];
        }

        // Create a new price with lookup_key
        $tax_unit_amount = $this->to_stripe_amount( $tax_amount );
        
        $price_data = [
            'currency'     => directorist_currency(),
            'product_data' => ['name' => 'Tax'],
            'unit_amount'  => $tax_unit_amount,
            'lookup_key'   => $lookup_key
        ];

        // Add recurring data if it's a subscription
        if ( $is_recurring && $interval && $interval_count ) {
            $price_data['recurring'] = [
                'interval'       => $interval,
                'interval_count' => $interval_count
            ];
        }

        return \DirectoristStripe\Stripe\Price::create( $price_data );
    }
}
