<?php
/**
 * Stripe webhook receiver.
 *
 * Single REST endpoint at `/doublescale/v1/integrations/stripe/webhook`.
 * Verifies the Stripe signature against the active mode's `webhook_secret`,
 * then routes the event by `data.object.metadata.source`:
 *
 *   - `'booking'`  → `do_action( 'doublescale_stripe_booking_event', $event, (int) $booking_id )`
 *   - (future)     → `'deal'`, `'invoice'`, etc.
 *
 * The CRM side never knows about booking-specific business logic; the
 * Booking adapter (`BookingStripeHandler`) subscribes to the booking action
 * and does the booking-specific work (status flips, order rows, logs).
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Stripe;

use DoubleScale\Pro\Vendor\Stripe\Webhook as StripeWebhook;
use DoubleScale\Pro\Vendor\Stripe\Exception\SignatureVerificationException;

defined( 'ABSPATH' ) || exit;

class Webhook {

	public function register_routes(): void {
		register_rest_route(
			'doublescale/v1',
			'/integrations/stripe/webhook',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function handle( \WP_REST_Request $request ) {
		$this->prime_gateway_listeners();

		$mode_settings = Integration::instance()->get_mode_settings();
		if ( ! $mode_settings ) {
			return new \WP_REST_Response( array( 'message' => 'Stripe is not configured.' ), 400 );
		}

		$payload   = $request->get_body();
		$signature = $request->get_header( 'stripe_signature' );

		if ( empty( $signature ) ) {
			return new \WP_REST_Response( array( 'message' => 'Missing Stripe signature header.' ), 400 );
		}

		try {
			$event = StripeWebhook::constructEvent(
				$payload,
				$signature,
				$mode_settings['webhook_secret']
			);
		} catch ( SignatureVerificationException $e ) {
			doublescale_get_logger()->warning(
				'Stripe webhook signature failed',
				array( 'code' => 'stripe_webhook_sig_failed', 'message' => $e->getMessage() )
			);
			return new \WP_REST_Response( array( 'message' => 'Invalid signature.' ), 400 );
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Stripe webhook parse failed',
				array( 'code' => 'stripe_webhook_parse_failed', 'message' => $e->getMessage() )
			);
			return new \WP_REST_Response( array( 'message' => 'Invalid payload.' ), 400 );
		}

		// Idempotency: Stripe retries the same `evt_…` on non-2xx / timeouts.
		// Without dedupe, `payment_intent.succeeded` arriving twice re-fires
		// `setPaymentStatus('completed')`, which re-emits the booking
		// `created` lifecycle event (notifications, calendar invites, logs)
		// every retry. Transient keyed on the Stripe event id is enough — the
		// 24h window is well past Stripe's retry schedule, and it's cheap.
		//
		// We CHECK the transient up front (so a duplicate exits fast) but
		// SET it only after the action runs without throwing. If a handler
		// fatals, the transient stays unset → Stripe retries → we get
		// another chance. The booking-level idempotency inside mark_paid
		// catches the case where the transient set succeeds but the
		// downstream side-effects already ran on a previous attempt.
		$event_id   = isset( $event->id ) ? (string) $event->id : '';
		$dedupe_key = '' !== $event_id ? 'ds_stripe_evt_' . md5( $event_id ) : '';
		if ( '' !== $dedupe_key && get_transient( $dedupe_key ) ) {
			doublescale_get_logger()->info(
				'Stripe webhook duplicate ignored',
				array( 'code' => 'stripe_webhook_duplicate', 'event_id' => $event_id, 'event_type' => $event->type ?? '' )
			);
			return new \WP_REST_Response( array( 'received' => true, 'duplicate' => true ), 200 );
		}

		$object   = $event->data->object ?? null;
		$metadata = self::metadata_to_array( $object->metadata ?? null );
		$source   = $metadata['source'] ?? '';

		// `charge.*` and `payment_intent.canceled` events don't always carry
		// our metadata directly (Stripe copies PI metadata onto the latest
		// charge, but disputes can fire on charges created via other flows).
		// When source is empty, infer it from the linked payment_intent: if
		// any booking has a meta row for that PI id, route as `booking`.
		// The booking handler still does its own lookup, so we only need
		// enough here to pick the right channel.
		if ( '' === $source && ! empty( $object ) ) {
			$pi_id = $this->extract_payment_intent_id( $object );
			if ( '' !== $pi_id && self::booking_owns_payment_intent( $pi_id ) ) {
				$source             = 'booking';
				$metadata['source'] = 'booking';
				$metadata['stripe_payment_intent_id'] = $pi_id;
			} elseif ( '' !== $pi_id && self::invoice_owns_payment_intent( $pi_id ) ) {
				$source             = 'invoice';
				$metadata['source'] = 'invoice';
				$metadata['stripe_payment_intent_id'] = $pi_id;
			}
		}

		// Extension point: let an add-on (e.g. the DoubleScale Subscriptions
		// plugin) claim an event the CRM core didn't recognize. A listener
		// inspects the raw object and returns `array( $source, $extra_metadata )`
		// to take ownership; the core then dispatches the per-source action
		// `doublescale_stripe_{$source}_event`. This keeps subscription / recurring
		// `invoice.*` routing knowledge in the owning add-on instead of here.
		// Booking and invoice resolution above stay inline by design — they are
		// core CRM features.
		if ( '' === $source && ! empty( $object ) ) {
			$resolved = apply_filters(
				'doublescale_stripe_resolve_webhook_source',
				array( '', array() ),
				$object,
				$event
			);
			if ( is_array( $resolved ) && '' !== (string) ( $resolved[0] ?? '' ) ) {
				$source = (string) $resolved[0];
				if ( isset( $resolved[1] ) && is_array( $resolved[1] ) ) {
					$metadata = array_merge( $metadata, $resolved[1] );
				}
				$metadata['source'] = $source;
			}
		}

		try {
			switch ( $source ) {
				case 'booking':
					$booking_id    = isset( $metadata['booking_id'] ) ? (int) $metadata['booking_id'] : 0;
					$action        = 'doublescale_stripe_booking_event';
					$has_listeners = has_action( $action );
					do_action( $action, $event, $booking_id );

					// Surface dropped events: a webhook arrived, signature verified,
					// metadata routed to `booking` — but no PHP listener picked it
					// up. Most common cause: the BookingStripeHandler singleton was
					// never instantiated in this REST request, so its
					// `add_action()` call in the constructor never ran. Flagging
					// this in the logger lets operators distinguish "Stripe is
					// silent" from "we ate the event."
					if ( ! $has_listeners ) {
						doublescale_get_logger()->error(
							'Stripe booking webhook had no listener',
							array(
								'source'     => 'booking-pro-stripe',
								'event_type' => $event->type ?? '',
								'booking_id' => $booking_id,
								'metadata'   => $metadata,
							)
						);
					}
					break;
				case 'invoice':
					$invoice_id    = isset( $metadata['invoice_id'] ) ? (int) $metadata['invoice_id'] : 0;
					$action        = 'doublescale_stripe_invoice_event';
					$has_listeners = has_action( $action );
					do_action( $action, $event, $invoice_id );
					if ( ! $has_listeners ) {
						doublescale_get_logger()->error(
							'Stripe invoice webhook had no listener',
							array(
								'source'     => 'sales-pro-stripe',
								'event_type' => $event->type ?? '',
								'invoice_id' => $invoice_id,
								'metadata'   => $metadata,
							)
						);
					}
					break;
				default:
					// Sources claimed by an add-on via the resolver filter (e.g.
					// `subscription`) are dispatched generically as
					// `doublescale_stripe_{$source}_event`, passing the resolved
					// metadata so the owning listener can extract its own local id.
					// Booking + invoice keep their dedicated arms above.
					if ( '' !== $source ) {
						$action        = "doublescale_stripe_{$source}_event";
						$has_listeners = has_action( $action );
						do_action( $action, $event, $metadata );
						if ( ! $has_listeners ) {
							doublescale_get_logger()->error(
								'Stripe webhook had no listener for source',
								array(
									'source'       => 'stripe-webhook',
									'event_source' => $source,
									'event_type'   => $event->type ?? '',
									'metadata'     => $metadata,
								)
							);
						}
					}
					do_action( 'doublescale_stripe_event', $event, $source );
					break;
			}
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Stripe webhook handler threw',
				array( 'code' => 'stripe_webhook_handler_threw', 'event_id' => $event_id, 'message' => $e->getMessage() )
			);
			// Return 500 so Stripe retries. Don't set the dedupe transient
			// since the event wasn't actually processed.
			return new \WP_REST_Response( array( 'message' => 'Handler error.' ), 500 );
		}

		if ( '' !== $dedupe_key ) {
			set_transient( $dedupe_key, 1, DAY_IN_SECONDS );
		}

		return new \WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Normalize a Stripe metadata bag to a plain associative array.
	 *
	 * Stripe SDK metadata is a `StripeObject` that keeps its values in a
	 * protected `$_values`, so a bare `(array)` cast produces mangled
	 * `"\0*\0_values"` keys instead of `source`/`subscription_id` — silently
	 * emptying the routing metadata. `toArray()` (or get_object_vars for a plain
	 * stdClass from tests) is the correct read.
	 *
	 * @param mixed $metadata StripeObject, array, stdClass, or null.
	 * @return array<string, mixed>
	 */
	private static function metadata_to_array( $metadata ): array {
		if ( is_array( $metadata ) ) {
			return $metadata;
		}
		if ( $metadata instanceof \DoubleScale\Pro\Vendor\Stripe\StripeObject ) {
			return $metadata->toArray();
		}
		if ( is_object( $metadata ) ) {
			return get_object_vars( $metadata );
		}
		return array();
	}

	/**
	 * Pull the related `pi_…` id out of whatever object Stripe shipped.
	 * Handles charges (`payment_intent` field) and PaymentIntents (`id`).
	 */
	private function extract_payment_intent_id( $object ): string {
		$type = isset( $object->object ) ? (string) $object->object : '';
		if ( 'payment_intent' === $type && ! empty( $object->id ) ) {
			return (string) $object->id;
		}
		if ( 'charge' === $type && ! empty( $object->payment_intent ) ) {
			return (string) $object->payment_intent;
		}
		// Disputes carry a `payment_intent` on the dispute object itself.
		if ( 'dispute' === $type && ! empty( $object->payment_intent ) ) {
			return (string) $object->payment_intent;
		}
		return '';
	}

	/**
	 * REST webhook requests may not have applied filters that lazily construct
	 * gateway singletons. Prime listeners before routing events.
	 *
	 * @return void
	 */
	private function prime_gateway_listeners(): void {
		$primers = array(
			'DoubleScale\\Pro\\Modules\\Booking\\PaymentGateways\\BookingStripeHandler',
			'DoubleScale\\Pro\\Modules\\Sales\\PaymentGateways\\StripeInvoiceWebhookHandler',
		);

		foreach ( $primers as $class ) {
			if ( ! class_exists( $class ) || ! method_exists( $class, 'instance' ) ) {
				continue;
			}
			try {
				$class::instance();
			} catch ( \Throwable $e ) {
				doublescale_get_logger()->error(
					'Stripe webhook gateway primer failed',
					array(
						'code'  => 'stripe_webhook_gateway_primer_failed',
						'class' => $class,
						'error' => $e->getMessage(),
					)
				);
			}
		}
	}

	/**
	 * Lightweight check: is there a booking that opened this PaymentIntent?
	 * Routes charge.refunded / charge.dispute.created (which carry the PI id
	 * but not the metadata) back into the booking handler. Goes through the
	 * Eloquent meta model so the table prefix matches whatever WPEloquent is
	 * configured for. Returns false on any error so a misconfigured DB
	 * doesn't misroute non-booking charges.
	 */
	private static function booking_owns_payment_intent( string $pi_id ): bool {
		if ( '' === $pi_id ) {
			return false;
		}
		try {
			return \DoubleScale\Modules\Booking\Models\BookingMetaModel::where( 'meta_key', 'stripe_payment_intent_id' )
				->where( 'meta_value', $pi_id )
				->exists();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * @param string $pi_id Payment intent id.
	 * @return bool
	 */
	private static function invoice_owns_payment_intent( string $pi_id ): bool {
		if ( '' === $pi_id || ! class_exists( \DoubleScale\Modules\Documents\Models\InvoiceModel::class, false ) ) {
			return false;
		}
		try {
			return \DoubleScale\Modules\Documents\Models\InvoiceModel::query()
				->where(
					function ( $query ) use ( $pi_id ) {
						$query->where( 'external_payment_ref', $pi_id )
							->orWhere( 'stripe_payment_intent_id', $pi_id );
					}
				)
				->exists();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

}
