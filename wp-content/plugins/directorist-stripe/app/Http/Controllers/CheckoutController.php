<?php

namespace DirectoristStripe\App\Http\Controllers;

defined( 'ABSPATH' ) || exit;

use DateTimeZone;
use WP_REST_Request;

use Directorist\DTO\Order\DTO as OrderDTO;
use Directorist\DTO\Payment\DTO as PaymentDTO;
use Directorist\Enums\Order\Status as OrderStatus;
use Directorist\Enums\Payment\Status as PaymentStatus;
use Directorist\Helpers\DateTime;

use DirectoristPricingPlan\App\Repositories\PlanOrderMetaRepository;
use DirectoristPricingPlan\App\DTO\PlanOrderMeta\DTO as PlanOrderMetaDTO;
use DirectoristPricingPlan\App\Enums\UserPackage\Status as UserPackageStatus;

use DirectoristStripe\App\Stripe;
use DirectoristStripe\Stripe\Charge;
use DirectoristStripe\Stripe\Checkout\Session;
use DirectoristStripe\Stripe\Invoice;
use DirectoristStripe\Stripe\Stripe as StripeSDK;
use DirectoristStripe\Stripe\Subscription;
use DirectoristStripe\WpMVC\RequestValidator\Validator;
use DirectoristStripe\WpMVC\Routing\Response;

class CheckoutController extends Controller {
    public function success( Validator $validator, WP_REST_Request $request ) {
        $validator->validate(
            [
                'session_id' => 'required|string',
                'order_id'   => 'numeric',
            ]
        );

        StripeSDK::setApiKey( directorist_stripe_get_secret_key() );

        $session_id        = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
        $order_id          = absint( $request->get_param( 'order_id' ) );
        $expected_order_id = null;

        if ( $order_id ) {
            $expected_order_id = $order_id;
        }

        try {
            $processed_order_id = $this->process_checkout_session_by_id( $session_id, $expected_order_id );

            if ( $processed_order_id ) {
                $this->redirect( directorist_payment_receipt_page_link( $processed_order_id ) );
            }
        } catch ( \Exception $e ) {
            error_log(
                sprintf(
                    'Directorist Stripe: Failed to process success redirect for session %s - %s',
                    $session_id,
                    $e->getMessage()
                )
            );
        }

        if ( $order_id ) {
            $this->redirect( directorist_payment_receipt_page_link( $order_id ) );
        }

        $this->redirect( directorist_payment_failure_url() );
    }

    public function webhook( WP_REST_Request $request ) {
        $test_webhook = get_directorist_option( 'stripe_test_webhook' );
        $live_webhook = get_directorist_option( 'stripe_live_webhook' );
        $event        = WebhookEventVerifier::verify(
            $request,
            is_array( $test_webhook ) ? ( $test_webhook['secret'] ?? '' ) : '',
            is_array( $live_webhook ) ? ( $live_webhook['secret'] ?? '' ) : ''
        );

        if ( is_wp_error( $event ) ) {
            return Response::send(
                [ 'message' => $event->get_error_message() ],
                $event->get_error_data()['status']
            );
        }

        $type = $event['type'] ?? '';
        $data = $event['data'] ?? [];

        do_action( 'directorist_stripe_webhook_received', $type, $data );

        $is_live    = $event['livemode'];
        $secret_key = get_directorist_option( $is_live ? 'stripe_live_sk' : 'stripe_test_sk' );

        StripeSDK::setApiKey( $secret_key ?: directorist_stripe_get_secret_key() );

        switch ( $type ) {
            case 'checkout.session.completed':
                $this->handle_checkout_session_completed( $data['object']['id'] ?? '' );
                break;
            case 'customer.subscription.deleted':
                $this->delete_subscription( $data['object']['id'] ?? '' );
                break;
            case 'customer.subscription.updated':
                $this->reconcile_subscription_status( $data['object'] ?? [] );
                break;
            case 'invoice.paid':
                $this->handle_invoice_paid( $data['object']['id'] ?? '' );
                break;
            case 'charge.updated':
                $this->handle_charge_updated( $data['object']['id'] ?? '' );
                break;
            default:
                break;
        }

        return Response::send(
            [
                'message' => 'Webhook received',
            ]
        );
    }

    /**
     * Keep local package access aligned with terminal Stripe subscriptions.
     *
     * @param array $subscription Stripe subscription webhook object.
     */
    public function reconcile_subscription_status( array $subscription ): void {
        $status = sanitize_key( (string) ( $subscription['status'] ?? '' ) );

        if ( ! in_array( $status, [ 'canceled', 'incomplete_expired' ], true ) ) {
            return;
        }

        $this->delete_subscription( sanitize_text_field( (string) ( $subscription['id'] ?? '' ) ) );
    }

    public function handle_checkout_session_completed( string $session_id ) {
        if ( empty( $session_id ) ) {
            return;
        }

        try {
            $this->process_checkout_session_by_id( $session_id );
        } catch ( \Exception $e ) {
            error_log(
                sprintf(
                    'Directorist Stripe: Failed to handle checkout session %s - %s',
                    $session_id,
                    $e->getMessage()
                )
            );
        }
    }

    public function handle_charge_updated( string $stripe_charge_id ) {
        if ( empty( $stripe_charge_id ) ) {
            return;
        }

        try {
            $stripe_charge = Charge::retrieve( $stripe_charge_id );

            if ( empty( $stripe_charge->metadata->order_id ) ) {
                return;
            }

            $order_id   = absint( $stripe_charge->metadata->order_id );
            $order      = $this->get_order_dto( $order_id );
            $order_meta = $this->get_plan_order_meta( $order_id );

            if ( ! $order ) {
                return;
            }

            if ( $this->is_pricing_plan_order( $order ) && $order_meta && ! empty( $order_meta->is_recurring ) ) {
                return;
            }

            $transaction_id = ! empty( $stripe_charge->payment_intent ) ? $stripe_charge->payment_intent : $stripe_charge->id;
            $is_paid        = ! empty( $stripe_charge->paid );

            $this->upsert_payment(
                $order_id,
                $transaction_id,
                $stripe_charge->amount / 100,
                strtoupper( $stripe_charge->currency ),
                $is_paid
            );

            if ( $is_paid ) {
                $this->mark_order_paid( $order, strtoupper( $stripe_charge->currency ) );
            } else {
                $this->mark_order_pending( $order );
            }
        } catch ( \Exception $e ) {
            error_log(
                sprintf(
                    'Directorist Stripe: Failed to handle charge %s - %s',
                    $stripe_charge_id,
                    $e->getMessage()
                )
            );
        }
    }

    public function handle_invoice_paid( string $stripe_invoice_id, ?int $legacy_order_id = null ) {
        if ( empty( $stripe_invoice_id ) ) {
            return;
        }

        try {
            $stripe_invoice = Invoice::retrieve( $stripe_invoice_id );

            $stripe_subscription_id = $this->get_invoice_subscription_id( $stripe_invoice );

            if ( '' === $stripe_subscription_id ) {
                return;
            }

            $transaction_id   = $stripe_invoice->id;
            $is_paid          = $this->is_invoice_paid( $stripe_invoice );
            $currency         = strtoupper( $stripe_invoice->currency );
            $paid_amount      = $this->get_invoice_payment_amount( $stripe_invoice );
            $existing_payment = $this->get_payment_by_transaction_id( $transaction_id );
            $order_date       = $this->get_invoice_order_date( $stripe_invoice );
            $payment_date     = $this->get_invoice_payment_date( $stripe_invoice );
            $period_start     = $this->get_invoice_period_start( $stripe_invoice );
            $period_end       = $this->get_invoice_period_end( $stripe_invoice );

            if ( $existing_payment ) {
                $this->update_payment( $existing_payment, $paid_amount, $currency, $is_paid );

                if ( 'subscription_cycle' === ( $stripe_invoice->billing_reason ?? '' ) ) {
                    $this->correct_invoice_record_dates( $existing_payment, $order_date, $payment_date );
                }

                if ( $is_paid ) {
                    $order = $this->get_order_dto( (int) $existing_payment->order_id );

                    if ( $order ) {
                        $this->mark_order_paid( $order, $currency );
                    }

                    $this->update_package_billing_start( $stripe_subscription_id, $period_start, $period_end );
                }
                return;
            }

            $stripe_subscription = Subscription::retrieve( $stripe_subscription_id );

            if ( ! $legacy_order_id ) {
                $legacy_order_id = $this->reconcile_legacy_subscription( $stripe_subscription, $stripe_invoice );
            }

            if ( $legacy_order_id && 'subscription_create' === ( $stripe_invoice->billing_reason ?? '' ) ) {
                return;
            }

            $initial_order_id = $legacy_order_id
                ? 0
                : absint( $stripe_subscription->metadata->order_id ?? 0 );

            if ( $initial_order_id && ! $this->order_has_payment( $initial_order_id ) ) {
                $order = $this->get_order_dto( $initial_order_id );

                if ( $order ) {
                    $this->upsert_payment( $initial_order_id, $transaction_id, $paid_amount, $currency, $is_paid, $payment_date );

                    if ( $is_paid ) {
                        $this->mark_order_paid( $order, $currency );
                        $this->update_package_subscription_details( $order, $stripe_subscription_id, $currency, $paid_amount );
                        $this->update_package_billing_start( $stripe_subscription_id, $period_start, $period_end );
                    }
                    return;
                }
            }

            $renewal_order_id = $this->create_renewal_order_from_invoice( $stripe_invoice );

            if ( $renewal_order_id ) {
                $this->upsert_payment( $renewal_order_id, $transaction_id, $paid_amount, $currency, $is_paid, $payment_date );

                if ( $is_paid ) {
                    $renewal_order = $this->get_order_dto( $renewal_order_id );

                    if ( $renewal_order ) {
                        $this->mark_order_paid( $renewal_order, $currency );
                    }

                    $this->update_package_billing_start( $stripe_subscription_id, $period_start, $period_end );
                }
            }
        } catch ( \Exception $e ) {
            error_log(
                sprintf(
                    'Directorist Stripe: Failed to handle invoice %s - %s',
                    $stripe_invoice_id,
                    $e->getMessage()
                )
            );
        }
    }

    private function reconcile_legacy_subscription( $stripe_subscription, $stripe_invoice ): int {
        $legacy_order_id = absint( $stripe_subscription->plan->metadata->order_id ?? 0 );

        if ( ! $legacy_order_id ) {
            return 0;
        }

        $migrated_order = directorist_order_repository()
            ->get_query_builder()
            ->where( 'legacy_id', $legacy_order_id )
            ->first();

        if ( ! $migrated_order || 'pricing_plan' !== ( $migrated_order->ref_type ?? '' ) || empty( $migrated_order->ref ) ) {
            return 0;
        }

        $linked = ( new LegacyWebhookController() )->ensure_package_subscription_link(
            $migrated_order,
            (string) $stripe_subscription->id,
            $stripe_invoice
        );

        return $linked ? (int) $migrated_order->id : 0;
    }

    public function delete_subscription( string $stripe_subscription_id ) {
        if ( empty( $stripe_subscription_id ) || ! directorist_stripe_is_pricing_plan_active() ) {
            return;
        }

        try {
            $user_package_repository = directorist_user_package_repository();
            $subscription            = $user_package_repository->get_by_subscription_id( $stripe_subscription_id );

            if ( ! $subscription ) {
                return;
            }

            if ( UserPackageStatus::CANCELLED === $subscription->status ) {
                return;
            }

            $user_package_repository->cancel_package( $subscription->id );
            do_action( 'directorist_pricing_plans_subscription_cancelled', $subscription->id );
        } catch ( \Exception $e ) {
            error_log(
                sprintf(
                    'Directorist Stripe: Failed to delete subscription %s - %s',
                    $stripe_subscription_id,
                    $e->getMessage()
                )
            );
        }
    }

    private function process_checkout_session_by_id( string $session_id, ?int $expected_order_id = null ): ?int {
        return $this->process_checkout_session( Session::retrieve( $session_id ), $expected_order_id );
    }

    private function process_checkout_session( $session, ?int $expected_order_id = null ): ?int {
        $order_id = $this->resolve_order_id_from_session( $session, $expected_order_id );

        if ( ! $order_id ) {
            return null;
        }

        $order      = $this->get_order_dto( $order_id );
        $order_meta = $this->get_plan_order_meta( $order_id );

        if ( ! $order ) {
            return null;
        }

        $payment_status  = $session->payment_status ?? '';
        $is_pricing_plan = $this->is_pricing_plan_order( $order );
        $is_recurring    = false;

        if ( $is_pricing_plan && $order_meta && ! empty( $order_meta->is_recurring ) ) {
            $is_recurring = true;
        }

        if ( ! $is_recurring ) {
            if ( Session::PAYMENT_STATUS_PAID !== $payment_status ) {
                return $order_id;
            }

            $transaction_id   = ! empty( $session->payment_intent ) ? $session->payment_intent : $session->id;
            $session_currency = $order->get_currency();

            if ( ! empty( $session->currency ) ) {
                $session_currency = strtoupper( $session->currency );
            }

            $this->upsert_payment(
                $order_id,
                $transaction_id,
                $this->get_session_payment_amount( $session ),
                $session_currency,
                true
            );

            $this->mark_order_paid( $order, $session_currency );
            return $order_id;
        }

        if ( Session::PAYMENT_STATUS_NO_PAYMENT_REQUIRED === $payment_status ) {
            $this->mark_order_paid( $order, $order->get_currency() );
            return $order_id;
        }

        if ( Session::PAYMENT_STATUS_PAID !== $payment_status || empty( $session->subscription ) ) {
            return $order_id;
        }

        $stripe_subscription_id = $this->normalize_stripe_id( $session->subscription );

        if ( empty( $stripe_subscription_id ) ) {
            return $order_id;
        }

        $stripe_subscription = Subscription::retrieve( $stripe_subscription_id );
        $invoice_id          = $this->normalize_stripe_id( $stripe_subscription->latest_invoice ?? null );

        if ( empty( $invoice_id ) ) {
            return $order_id;
        }

        $stripe_invoice = Invoice::retrieve( $invoice_id );
        $currency       = strtoupper( $stripe_invoice->currency );

        $this->upsert_payment(
            $order_id,
            $stripe_invoice->id,
            $this->get_invoice_payment_amount( $stripe_invoice ),
            $currency,
            true
        );

        $this->mark_order_paid( $order, $currency );
        $this->update_package_subscription_details( $order, $stripe_subscription_id, $currency, $this->get_invoice_payment_amount( $stripe_invoice ) );

        return $order_id;
    }

    private function resolve_order_id_from_session( $session, ?int $expected_order_id = null ): int {
        $metadata_order_id = absint( $session->metadata->order_id ?? 0 );
        $client_order_id   = absint( $session->client_reference_id ?? 0 );
        $order_id          = 0;

        if ( $metadata_order_id ) {
            $order_id = $metadata_order_id;
        } elseif ( $client_order_id ) {
            $order_id = $client_order_id;
        } elseif ( null !== $expected_order_id ) {
            $order_id = $expected_order_id;
        }

        if ( $expected_order_id && $order_id && $expected_order_id !== $order_id ) {
            throw new \Exception( 'Stripe session order mismatch.' );
        }

        return $order_id;
    }

    private function get_session_payment_amount( $session ): float {
        if ( null !== $session->amount_total ) {
            return $session->amount_total / 100;
        }

        return 0;
    }

    private function get_invoice_payment_amount( $stripe_invoice ): float {
        if ( $this->is_invoice_paid( $stripe_invoice ) ) {
            return (float) $stripe_invoice->amount_paid / 100;
        }

        return (float) $stripe_invoice->amount_due / 100;
    }

    private function is_invoice_paid( $stripe_invoice ): bool {
        return ( $stripe_invoice->status ?? '' ) === 'paid';
    }

    private function create_renewal_order_from_invoice( $stripe_invoice ): ?int {
        if ( ! directorist_stripe_is_pricing_plan_active() ) {
            return null;
        }

        $stripe_subscription_id = $this->get_invoice_subscription_id( $stripe_invoice );

        if ( '' === $stripe_subscription_id ) {
            return null;
        }

        $package = directorist_user_package_repository()->get_by_subscription_id( $stripe_subscription_id );

        if ( ! $package ) {
            return null;
        }

        $source_order = directorist_user_package_repository()->get_last_order( $package->id );

        if ( ! $source_order ) {
            return null;
        }

        if ( ! $this->is_pricing_plan_order( $source_order ) ) {
            return null;
        }

        $currency = strtoupper( $stripe_invoice->currency );

        $order_dto = ( new OrderDTO )
            ->set_user_id( $source_order->get_user_id() )
            ->set_ref_type( $source_order->get_ref_type() )
            ->set_ref( $source_order->get_ref() )
            ->set_amount( $source_order->get_amount() )
            ->set_sub_total( $source_order->get_sub_total() )
            ->set_currency( $currency )
            ->set_status( OrderStatus::PENDING );

        $order_date = $this->get_invoice_order_date( $stripe_invoice );

        if ( $order_date ) {
            $order_dto->set_created_at( $order_date );
        }

        if ( $source_order->is_initialized( 'listing_id' ) && null !== $source_order->get_listing_id() ) {
            $order_dto->set_listing_id( $source_order->get_listing_id() );
        }

        if ( $source_order->is_initialized( 'is_featured_listing' ) && null !== $source_order->get_is_featured_listing() ) {
            $order_dto->set_is_featured_listing( $source_order->get_is_featured_listing() );
        }

        if ( $source_order->is_initialized( 'tax_type' ) && '' !== $source_order->get_tax_type() ) {
            $order_dto->set_tax_type( $source_order->get_tax_type() )->set_tax_rate( $source_order->get_tax_rate() );
        }

        $new_order_id = directorist_order_repository()->create( $order_dto );

        if ( $new_order_id ) {
            $period_end = $this->get_invoice_period_end( $stripe_invoice );

            $this->plan_order_meta_repository()->upsert_by_order_id(
                ( new PlanOrderMetaDTO )
                    ->set_order_id( $new_order_id )
                    ->set_is_recurring( true )
                    ->set_is_trial( false )
                    ->set_current_period_end( $period_end )
            );
        }

        return $new_order_id;
    }

    /**
     * Get the subscription ID from current and legacy Stripe invoice schemas.
     *
     * @param object $stripe_invoice Stripe invoice.
     */
    public function get_invoice_subscription_id( $stripe_invoice ): string {
        $candidates = [
            $stripe_invoice->subscription ?? null,
            $stripe_invoice->parent->subscription_details->subscription ?? null,
        ];

        foreach ( $stripe_invoice->lines->data ?? [] as $line ) {
            $candidates[] = $line->parent->subscription_item_details->subscription ?? null;
        }

        foreach ( $candidates as $candidate ) {
            if ( is_string( $candidate ) && '' !== $candidate ) {
                return $candidate;
            }

            if ( is_object( $candidate ) && ! empty( $candidate->id ) ) {
                return (string) $candidate->id;
            }
        }

        return '';
    }

    /**
     * Get the billing-period start from a Stripe invoice.
     *
     * @param object $stripe_invoice Stripe invoice.
     */
    public function get_invoice_period_start( $stripe_invoice ): ?DateTime {
        $period_start = 0;

        foreach ( $stripe_invoice->lines->data ?? [] as $line ) {
            $line_period_start = (int) ( $line->period->start ?? 0 );
            $period_start      = max( $period_start, $line_period_start );
        }

        if ( ! $period_start ) {
            $period_start = (int) ( $stripe_invoice->period_start ?? 0 );
        }

        return $period_start ? $this->unix_to_datetime( $period_start ) : null;
    }

    /**
     * Use the billing-period start as the historical renewal order date.
     *
     * @param object $stripe_invoice Stripe invoice.
     */
    private function get_invoice_order_date( $stripe_invoice ): ?DateTime {
        $period_start = $this->get_invoice_period_start( $stripe_invoice );

        if ( $period_start ) {
            return $period_start;
        }

        $invoice_created = (int) ( $stripe_invoice->created ?? 0 );

        return $invoice_created ? $this->unix_to_datetime( $invoice_created ) : null;
    }

    /**
     * Get the date Stripe recorded payment for an invoice.
     *
     * @param object $stripe_invoice Stripe invoice.
     */
    private function get_invoice_payment_date( $stripe_invoice ): ?DateTime {
        $paid_at = (int) ( $stripe_invoice->status_transitions->paid_at ?? 0 );

        if ( $paid_at ) {
            return $this->unix_to_datetime( $paid_at );
        }

        $invoice_created = (int) ( $stripe_invoice->created ?? 0 );

        return $invoice_created ? $this->unix_to_datetime( $invoice_created ) : null;
    }

    private function get_invoice_period_end( $stripe_invoice ): ?DateTime {
        $period_end = 0;

        foreach ( $stripe_invoice->lines->data ?? [] as $line ) {
            $line_period_end = (int) ( $line->period->end ?? 0 );
            $period_end      = max( $period_end, $line_period_end );
        }

        if ( ! $period_end ) {
            $period_end = (int) ( $stripe_invoice->period_end ?? 0 );
        }

        return $period_end ? $this->unix_to_datetime( $period_end ) : null;
    }

    private function upsert_payment( int $order_id, string $transaction_id, float $amount, string $currency, bool $is_paid, ?DateTime $created_at = null ) {
        if ( empty( $transaction_id ) ) {
            return;
        }

        $existing_payment = $this->get_payment_by_transaction_id( $transaction_id );

        if ( $existing_payment ) {
            $this->update_payment( $existing_payment, $amount, $currency, $is_paid );
            return;
        }

        $payment_dto = ( new PaymentDTO )
            ->set_order_id( $order_id )
            ->set_transaction_id( $transaction_id )
            ->set_amount( $amount )
            ->set_currency( $currency )
            ->set_status( $is_paid ? PaymentStatus::PAID : PaymentStatus::PENDING )
            ->set_method( Stripe::get_key() );

        if ( $created_at ) {
            $payment_dto->set_created_at( $created_at );
        }

        directorist_payment_repository()->create( $payment_dto );
    }

    /**
     * Correct historical order and payment dates when an invoice was replayed.
     *
     * @param object        $payment Payment record.
     * @param DateTime|null $order_date Stripe billing-period start.
     * @param DateTime|null $payment_date Stripe paid date.
     */
    private function correct_invoice_record_dates( $payment, ?DateTime $order_date, ?DateTime $payment_date ): void {
        if ( $order_date ) {
            directorist_order_repository()->silent_update(
                ( new OrderDTO )
                    ->set_id( (int) $payment->order_id )
                    ->set_created_at( $order_date )
            );
        }

        if ( $payment_date ) {
            directorist_payment_repository()->update(
                ( new PaymentDTO )
                    ->set_id( (int) $payment->id )
                    ->set_created_at( $payment_date )
            );
        }
    }

    /**
     * Keep the package billing start aligned with its current Stripe period.
     *
     * @param string        $subscription_id Stripe subscription ID.
     * @param DateTime|null $period_start Stripe period start.
     * @param DateTime|null $period_end Stripe period end.
     */
    private function update_package_billing_start( string $subscription_id, ?DateTime $period_start, ?DateTime $period_end ): void {
        if ( ! $period_start || ! $period_end ) {
            return;
        }

        $repository = directorist_user_package_repository();
        $package    = $repository->get_by_subscription_id( $subscription_id );

        if ( ! $package || empty( $package->current_period_end ) ) {
            return;
        }

        $package_period_end = new DateTime( (string) $package->current_period_end, new DateTimeZone( 'UTC' ) );

        if ( $package_period_end->getTimestamp() !== $period_end->getTimestamp() ) {
            return;
        }

        $repository->update(
            $repository->to_dto( $package )
                ->set_started_at( $period_start )
        );
    }

    private function update_payment( $existing_payment, float $amount, string $currency, bool $is_paid ) {
        $payment_dto = ( new PaymentDTO )
            ->set_id( $existing_payment->id )
            ->set_amount( $amount )
            ->set_currency( $currency )
            ->set_status( $is_paid ? PaymentStatus::PAID : PaymentStatus::PENDING );

        directorist_payment_repository()->update( $payment_dto );
    }

    private function mark_order_paid( OrderDTO $order, ?string $currency = null ) {
        if ( $order->get_status() === OrderStatus::PAID && ( ! $currency || $order->get_currency() === $currency ) ) {
            return;
        }

        $order_dto = ( new OrderDTO )
            ->set_id( $order->get_id() )
            ->set_status( OrderStatus::PAID );

        if ( $currency ) {
            $order_dto->set_currency( $currency );
        }

        directorist_order_repository()->update( $order_dto );
    }

    private function mark_order_pending( OrderDTO $order ) {
        if ( $order->get_status() === OrderStatus::PAID || $order->get_status() === OrderStatus::PENDING ) {
            return;
        }

        directorist_order_repository()->update(
            ( new OrderDTO )
                ->set_id( $order->get_id() )
                ->set_status( OrderStatus::PENDING )
        );
    }

    private function order_has_payment( int $order_id ): bool {
        return (bool) directorist_payment_repository()
            ->get_query_builder()
            ->where( 'order_id', $order_id )
            ->count( 'id' );
    }

    private function get_order_dto( int $order_id ): ?OrderDTO {
        return directorist_order_repository()->to_dto( directorist_order_repository()->get_by_id( $order_id ) );
    }

    private function is_pricing_plan_order( OrderDTO $order ): bool {
        if ( ! $order->is_initialized( 'ref_type' ) || ! $order->is_initialized( 'ref' ) ) {
            return false;
        }

        if ( 'pricing_plan' !== $order->get_ref_type() ) {
            return false;
        }

        if ( empty( $order->get_ref() ) ) {
            return false;
        }

        return true;
    }

    private function get_plan_order_meta( int $order_id ) {
        $repository = $this->plan_order_meta_repository();

        if ( ! $repository ) {
            return null;
        }

        return $repository->get_by_order_id( $order_id );
    }

    private function plan_order_meta_repository() {
        if ( ! function_exists( 'directorist_pricing_plans_singleton' ) ) {
            return null;
        }

        return directorist_pricing_plans_singleton( PlanOrderMetaRepository::class );
    }

    private function update_package_subscription_details( OrderDTO $order, string $subscription_id, string $currency, float $amount ) {
        if ( ! directorist_stripe_is_pricing_plan_active() ) {
            return;
        }

        if ( ! $this->is_pricing_plan_order( $order ) ) {
            return;
        }

        $order_meta = $this->get_plan_order_meta( $order->get_id() );

        if ( ! $order_meta || empty( $order_meta->is_recurring ) ) {
            return;
        }

        $plan = directorist_get_pricing_plan_by_id( (int) $order->get_ref() );

        if ( ! $plan ) {
            return;
        }

        $package = directorist_get_current_package( (int) $plan->directory_type_id, $order->get_user_id() );

        if ( ! $package ) {
            return;
        }

        $package_dto = directorist_user_package_repository()->to_dto( $package )
            ->set_subscription_id( $subscription_id )
            ->set_subscription_method( Stripe::get_key() )
            ->set_subscription_currency( $currency )
            ->set_subscription_amount( $amount );

        directorist_user_package_repository()->update( $package_dto );
    }

    private function normalize_stripe_id( $value ): string {
        if ( is_string( $value ) ) {
            return $value;
        }

        if ( is_object( $value ) && ! empty( $value->id ) ) {
            return (string) $value->id;
        }

        return '';
    }

    private function unix_to_datetime( $unix_timestamp ) {
        $timezone = new DateTimeZone( 'UTC' );
        return new DateTime( '@' . $unix_timestamp, $timezone );
    }

    private function get_payment_by_transaction_id( string $transaction_id ) {
        return directorist_payment_repository()
            ->get_query_builder()
            ->where( 'transaction_id', $transaction_id )
            ->first();
    }

    private function redirect( string $url ) {
        wp_safe_redirect( $url );
        exit;
    }
}
