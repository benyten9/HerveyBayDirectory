<?php
/**
 * Booking Stripe glue: AJAX, process_payment action, webhooks, timeout guard.
 *
 * @package DoubleScale\Pro\Modules\Booking\PaymentGateways
 */

namespace DoubleScale\Pro\Modules\Booking\PaymentGateways;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Payment\GatewayManager;
use DoubleScale\Modules\Booking\Models\BookingMetaModel;
use DoubleScale\Modules\Booking\Models\BookingModel;
use DoubleScale\Modules\Booking\Services\BookingEvents;
use DoubleScale\Pro\Modules\Integrations\Stripe\PaymentService;
use DoubleScale\Pro\Modules\Integrations\Stripe\Utils as StripeUtils;
use DoubleScale\Pro\Modules\Pro\Payment\StripeGateway;

/**
 * BookingStripeHandler class.
 */
final class BookingStripeHandler {

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_doublescale_booking_init_stripe', array( $this, 'ajax_init_stripe' ) );
		add_action( 'wp_ajax_nopriv_doublescale_booking_init_stripe', array( $this, 'ajax_init_stripe' ) );
		add_action( 'wp_ajax_doublescale_booking_confirm_stripe', array( $this, 'ajax_confirm_stripe' ) );
		add_action( 'wp_ajax_nopriv_doublescale_booking_confirm_stripe', array( $this, 'ajax_confirm_stripe' ) );
		add_action( 'doublescale_booking_process_payment', array( $this, 'process_payment' ), 10, 2 );
		add_action( 'doublescale_stripe_booking_event', array( $this, 'handle_webhook_event' ), 10, 2 );
		add_filter( 'doublescale_booking_should_cancel_for_payment_timeout', array( $this, 'should_cancel_for_payment_timeout' ), 10, 2 );
	}

	/**
	 * @param bool  $should_cancel Default decision.
	 * @param mixed $booking       Booking model.
	 * @return bool
	 */
	public function should_cancel_for_payment_timeout( bool $should_cancel, $booking ): bool {
		if ( ! $should_cancel ) {
			return false;
		}
		if ( ! $booking || ! method_exists( $booking, 'get_meta' ) ) {
			return $should_cancel;
		}
		$pi_id = $booking->get_meta( 'stripe_payment_intent_id' );
		if ( ! $pi_id ) {
			return $should_cancel;
		}

		try {
			$service = new PaymentService();
			$pi      = $service->retrieve_payment_intent( $pi_id );
		} catch ( \Throwable $e ) {
			return $should_cancel;
		}

		if ( ! $pi || empty( $pi->status ) ) {
			return $should_cancel;
		}

		$gateway = $this->stripe_gateway();
		if ( ! $gateway ) {
			return $should_cancel;
		}

		if ( 'succeeded' === $pi->status ) {
			$gateway->record_paid( new BookingPayableSubject( $booking ), $pi );
			return false;
		}

		if ( in_array( $pi->status, array( 'processing', 'requires_action', 'requires_confirmation' ), true ) ) {
			return false;
		}

		return $should_cancel;
	}

	/**
	 * @return void
	 */
	private function verify_nonce_or_die(): void {
		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
		if ( wp_verify_nonce( $nonce, 'doublescale_booking' ) ) {
			return;
		}
		if ( is_user_logged_in() && current_user_can( 'doublescale_access' ) ) {
			return;
		}
		wp_send_json_error( array( 'message' => __( 'Security check failed.', 'doublescale' ) ), 403 );
	}

	/**
	 * @param mixed $booking Booking.
	 * @param array $args    Payment args.
	 * @return void
	 */
	public function process_payment( $booking, $args ): void {
		$payment_method = isset( $args['payment_method'] ) ? sanitize_text_field( $args['payment_method'] ) : '';
		if ( 'stripe' !== $payment_method ) {
			return;
		}

		$gateway = $this->stripe_gateway();
		if ( ! $gateway ) {
			wp_send_json_error( array( 'message' => __( 'Stripe is not configured.', 'doublescale' ) ) );
			return;
		}

		try {
			$subject = new BookingPayableSubject( $booking );
			$result  = $gateway->init( $subject );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
				return;
			}

			wp_send_json_success( $result );
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Stripe booking payment failed',
				array( 'code' => 'stripe_booking_payment_failed', 'message' => $e->getMessage() )
			);
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * @return void
	 */
	public function ajax_init_stripe(): void {
		$this->verify_nonce_or_die();
		try {
			$booking_hash = isset( $_POST['booking_id'] ) ? sanitize_text_field( wp_unslash( $_POST['booking_id'] ) ) : '';
			if ( '' === $booking_hash ) {
				throw new \Exception( __( 'Invalid booking ID', 'doublescale' ) );
			}

			$booking = BookingModel::getByHashId( $booking_hash );
			if ( ! $booking ) {
				throw new \Exception( __( 'Booking not found', 'doublescale' ) );
			}

			$bookable = $booking->getBookableEntity();
			if ( ! $bookable ) {
				throw new \Exception( __( 'Bookable entity not found for this booking', 'doublescale' ) );
			}

			$payments_settings = $bookable->payments_settings ?? array();
			if ( empty( $payments_settings['enable_payment'] ) || empty( $payments_settings['enable_stripe'] ) ) {
				throw new \Exception( __( 'Stripe is not enabled for this booking', 'doublescale' ) );
			}

			$gateway = $this->stripe_gateway();
			if ( ! $gateway ) {
				throw new \Exception( __( 'Stripe is not configured.', 'doublescale' ) );
			}

			$result = $gateway->init( new BookingPayableSubject( $booking ) );
			if ( is_wp_error( $result ) ) {
				throw new \Exception( $result->get_error_message() );
			}

			wp_send_json_success( $result );
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Stripe AJAX init failed',
				array( 'code' => 'stripe_ajax_init_failed', 'message' => $e->getMessage() )
			);
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * @return void
	 */
	public function ajax_confirm_stripe(): void {
		$this->verify_nonce_or_die();
		try {
			$booking_hash = isset( $_POST['booking_id'] ) ? sanitize_text_field( wp_unslash( $_POST['booking_id'] ) ) : '';
			if ( '' === $booking_hash ) {
				throw new \Exception( __( 'Invalid booking ID', 'doublescale' ) );
			}

			$booking = BookingModel::getByHashId( $booking_hash );
			if ( ! $booking ) {
				throw new \Exception( __( 'Booking not found', 'doublescale' ) );
			}

			$gateway = $this->stripe_gateway();
			if ( ! $gateway ) {
				throw new \Exception( __( 'Stripe is not configured.', 'doublescale' ) );
			}

			$result = $gateway->confirm( new BookingPayableSubject( $booking ) );
			if ( is_wp_error( $result ) ) {
				throw new \Exception( $result->get_error_message() );
			}

			wp_send_json_success( $result );
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->warning(
				'Stripe confirm poll failed',
				array( 'code' => 'stripe_confirm_poll_failed', 'message' => $e->getMessage() )
			);
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * @param object $event      Stripe event.
	 * @param int    $booking_id Booking id from metadata.
	 * @return void
	 */
	public function handle_webhook_event( $event, int $booking_id ): void {
		$object = $event->data->object ?? null;
		if ( ! $object || empty( $object->id ) ) {
			return;
		}

		$booking = $booking_id > 0 ? BookingModel::find( $booking_id ) : null;
		if ( ! $booking ) {
			$pi_id = $this->pi_id_from_object( $object );
			if ( '' !== $pi_id ) {
				$booking = $this->find_booking_by_pi( $pi_id );
			}
		}
		if ( ! $booking ) {
			doublescale_get_logger()->warning(
				'Stripe booking webhook: booking not found',
				array(
					'code'       => 'stripe_booking_not_found',
					'event_type' => $event->type ?? '',
					'object_id'  => (string) $object->id,
				)
			);
			return;
		}

		$gateway = $this->stripe_gateway();
		if ( ! $gateway ) {
			return;
		}

		$subject = new BookingPayableSubject( $booking );

		switch ( $event->type ) {
			case 'payment_intent.succeeded':
				$gateway->record_paid( $subject, $object );
				break;
			case 'payment_intent.payment_failed':
				$this->mark_failed( $booking, $object, 'failed' );
				break;
			case 'payment_intent.canceled':
				$this->mark_failed( $booking, $object, 'canceled' );
				break;
			case 'charge.refunded':
				$this->mark_refunded( $booking, $object );
				break;
			case 'charge.dispute.created':
				$this->mark_disputed( $booking, $object );
				break;
			default:
				doublescale_get_logger()->info(
					'Stripe booking webhook ignored',
					array( 'code' => 'stripe_booking_webhook_ignored', 'event' => $event->type )
				);
		}
	}

	/**
	 * @return StripeGateway|null
	 */
	private function stripe_gateway(): ?StripeGateway {
		$gateway = GatewayManager::instance()->get( GatewayManager::CONTEXT_BOOKING, 'stripe' );
		return $gateway instanceof StripeGateway ? $gateway : null;
	}

	/**
	 * @param object $object Stripe object.
	 * @return string
	 */
	private function pi_id_from_object( $object ): string {
		$type = isset( $object->object ) ? (string) $object->object : '';
		if ( 'payment_intent' === $type ) {
			return (string) ( $object->id ?? '' );
		}
		if ( in_array( $type, array( 'charge', 'dispute' ), true ) && ! empty( $object->payment_intent ) ) {
			return (string) $object->payment_intent;
		}
		return '';
	}

	/**
	 * @param string $pi_id Payment intent id.
	 * @return BookingModel|null
	 */
	private function find_booking_by_pi( string $pi_id ): ?BookingModel {
		$meta = BookingMetaModel::where( 'meta_key', 'stripe_payment_intent_id' )
			->where( 'meta_value', $pi_id )
			->orderBy( 'id', 'desc' )
			->first();
		return $meta ? BookingModel::find( (int) $meta->booking_id ) : null;
	}

	/**
	 * @param BookingModel $booking        Booking.
	 * @param object       $payment_intent Stripe PI.
	 * @param string       $reason         `failed` or `canceled`.
	 * @return void
	 */
	private function mark_failed( BookingModel $booking, $payment_intent, string $reason = 'failed' ): void {
		$booking->setPaymentStatus( 'failed' );
		$booking->update_meta( 'stripe_failed_payment_intent_id', $payment_intent->id );

		$amount   = ( (int) ( $payment_intent->amount ?? 0 ) ) / 100;
		$currency = strtoupper( (string) ( $payment_intent->currency ?? '' ) );

		if ( 'canceled' === $reason ) {
			$cancel_reason = (string) ( $payment_intent->cancellation_reason ?? 'expired' );
			$booking->logs()->create(
				array(
					'type'    => 'error',
					'message' => __( 'Payment canceled', 'doublescale' ),
					'details' => sprintf(
						/* translators: 1: amount, 2: currency, 3: cancellation reason */
						__( 'Payment intent for %1$s %2$s was canceled: %3$s', 'doublescale' ),
						$amount,
						$currency,
						$cancel_reason
					),
				)
			);
			return;
		}

		$booking->logs()->create(
			array(
				'type'    => 'error',
				'message' => __( 'Payment failed', 'doublescale' ),
				'details' => sprintf(
					/* translators: 1: amount, 2: currency, 3: error */
					__( 'Payment of %1$s %2$s failed: %3$s', 'doublescale' ),
					$amount,
					$currency,
					$payment_intent->last_payment_error->message ?? 'Unknown error'
				),
			)
		);
	}

	/**
	 * @param BookingModel $booking Booking.
	 * @param object       $charge  Stripe charge.
	 * @return void
	 */
	private function mark_refunded( BookingModel $booking, $charge ): void {
		$amount_refunded = (int) ( $charge->amount_refunded ?? 0 );
		$amount_total    = (int) ( $charge->amount ?? 0 );
		$is_full_refund  = $amount_refunded > 0 && $amount_refunded >= $amount_total;
		$currency        = strtoupper( (string) ( $charge->currency ?? '' ) );

		$booking->update_meta( 'stripe_refunded_amount', $amount_refunded );
		$booking->update_meta( 'stripe_refunded_at', current_time( 'mysql', true ) );

		if ( $booking->order ) {
			$booking->order()->update( array( 'status' => $is_full_refund ? 'refunded' : 'partially_refunded' ) );
		}

		$booking->logs()->create(
			array(
				'type'    => $is_full_refund ? 'warning' : 'info',
				'message' => $is_full_refund
					? __( 'Payment refunded — booking cancelled', 'doublescale' )
					: __( 'Payment partially refunded', 'doublescale' ),
				'details' => sprintf(
					/* translators: 1: refunded amount, 2: currency, 3: charge id */
					__( 'Refunded %1$s %2$s. Charge: %3$s', 'doublescale' ),
					StripeUtils::from_stripe_amount( $amount_refunded, $currency ),
					$currency,
					(string) ( $charge->id ?? '' )
				),
			)
		);

		if ( ! $is_full_refund ) {
			return;
		}

		if ( $booking->isCancelled() || 'completed' === $booking->status ) {
			return;
		}

		$booking->cancelled_by = array(
			'type'   => 'system',
			'reason' => 'refunded',
		);
		$booking->status       = 'cancelled';
		$booking->save();

		BookingEvents::emit(
			'cancelled',
			(int) $booking->id,
			array(
				'actor'  => 'system',
				'reason' => 'refunded',
			)
		);
	}

	/**
	 * @param BookingModel $booking Booking.
	 * @param object       $dispute Stripe dispute.
	 * @return void
	 */
	private function mark_disputed( BookingModel $booking, $dispute ): void {
		$booking->update_meta( 'stripe_dispute_id', (string) ( $dispute->id ?? '' ) );
		$booking->update_meta( 'stripe_dispute_status', (string) ( $dispute->status ?? '' ) );
		$booking->update_meta( 'stripe_dispute_reason', (string) ( $dispute->reason ?? '' ) );

		if ( $booking->order ) {
			$booking->order()->update( array( 'status' => 'disputed' ) );
		}

		doublescale_get_logger()->warning(
			'Stripe booking dispute opened',
			array(
				'code'       => 'stripe_booking_dispute',
				'booking_id' => (int) $booking->id,
				'dispute_id' => (string) ( $dispute->id ?? '' ),
				'reason'     => (string) ( $dispute->reason ?? '' ),
			)
		);

		$booking->logs()->create(
			array(
				'type'    => 'error',
				'message' => __( 'Dispute opened by cardholder', 'doublescale' ),
				'details' => sprintf(
					/* translators: 1: dispute reason, 2: dispute id */
					__( 'Reason: %1$s. Dispute: %2$s. Review in your Stripe dashboard.', 'doublescale' ),
					(string) ( $dispute->reason ?? 'unknown' ),
					(string) ( $dispute->id ?? '' )
				),
			)
		);
	}
}
