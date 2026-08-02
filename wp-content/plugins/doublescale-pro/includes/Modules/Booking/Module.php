<?php
/**
 * Booking Pro module bootstrap.
 *
 * Pro extension layer for the free Booking module. Adds Stripe payments,
 * Apple/Google/Outlook/Zoom calendar integrations, the waiting-list handler,
 * and the SMS-notifier bridge to the CRM-wide Twilio integration.
 *
 * This module depends on free's `booking` module via `dependencies()`. When
 * free's Booking module is missing (e.g. Pro plugin installed standalone),
 * `ModuleRegistry::sort_by_dependencies()` still topologically sorts this
 * module but its `register()`/`boot()` no-op via a defensive `class_exists`
 * guard. Slug is `booking-pro`; namespace is `DoubleScale\Pro\Modules\Booking`.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Pro\Modules\Booking;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'booking-pro';
	}

	public function label(): string {
		return __( 'Booking Pro', 'doublescale' );
	}

	public function description(): string {
		return __( 'Pro extensions for the Booking module: calendar integrations, Stripe payments, waiting list, SMS notifications.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	public function is_toggleable(): bool {
		return false;
	}

	public function dependencies(): array {
		return array( 'core', 'booking' );
	}

	/**
	 * Defensive presence check: free's Booking module must be loaded for any
	 * of pro's integrations to function (they extend free abstracts). The
	 * `dependencies()` declaration above is advisory in the current registry
	 * (`ModuleRegistry::sort_by_dependencies()` silently continues past
	 * missing deps), so this guard belt-and-braces protects against Pro being
	 * activated standalone.
	 */
	private function free_booking_present(): bool {
		return class_exists( \DoubleScale\Modules\Booking\Module::class, false );
	}

	public function register( Container $container ): void {
		if ( ! $this->free_booking_present() ) {
			return;
		}

		// Enqueue the Pro renderer bundle on public booking pages so
		// `src/renderer/booking-pro/index.tsx` runs its addFilter calls and
		// overrides free renderer placeholders (Stripe payment component,
		// price display, waiting-list submit text, etc.). Without this hook
		// the Pro build at `build/renderer/index.js` is never loaded.
		Renderer\ProRendererLoader::register();

		$container->singleton(
			Services\WaitingListHandler::class,
			static fn () => Services\WaitingListHandler::instance()
		);

		$container->singleton(
			Services\BookingSmsNotifier::class,
			static fn () => new Services\BookingSmsNotifier()
		);

		// Filter listeners — register here so free's boot() iteration sees them.
		// `ModuleRegistry::boot()` runs ALL modules' `register()` first, then
		// ALL modules' `boot()`. Because booking-pro depends on booking, the
		// order is: booking.register() → booking-pro.register() → booking.boot()
		// → booking-pro.boot(). So filters added in booking-pro.register() are
		// in place before free's boot() reads the `doublescale_booking_integrations`
		// filter or `doublescale_booking_payment_gateways` is consumed.

		add_filter(
			'doublescale_booking_payment_gateways',
			static function ( $gateways ) {
				// Consumer at EventModel.php:994-1000 reads `slug` off each entry,
				// so we register the INSTANCE (has `$slug = 'stripe'`), not the
				// class string. PaymentGateway::instance() memoises per-subclass
				// via `self::$instances[static::class]`.
				$gateways[] = \DoubleScale\Pro\Modules\Pro\Payment\StripeGateway::instance();
				return $gateways;
			}
		);

		add_filter(
			'doublescale_booking_integrations',
			static function ( $integrations ) {
				return array_merge(
					(array) $integrations,
					array(
						Integrations\Apple\Integration::class,
						Integrations\Google\Integration::class,
						Integrations\Outlook\Integration::class,
						Integrations\Zoom\Integration::class,
					)
				);
			}
		);

		add_filter(
			'doublescale_booking_payment_provider_status',
			static function ( $payload ) {
				$payload = (array) $payload;
				if ( class_exists( \DoubleScale\Pro\Modules\Integrations\Stripe\Integration::class ) ) {
					$payload['configured'] = (bool) \DoubleScale\Pro\Modules\Integrations\Stripe\Integration::instance()->is_configured();
				}
				return $payload;
			}
		);

		add_filter(
			'doublescale_booking_sms_provider_status',
			static function ( $payload ) {
				$payload  = (array) $payload;
				$registry = doublescale_resolve( \DoubleScale\Pro\Modules\Inbox\Services\MessageProviderRegistry::class );
				if ( $registry ) {
					$sms_provider = $registry->get_provider( 'sms' );
					if ( $sms_provider ) {
						$payload['configured'] = (bool) $sms_provider->is_configured();
						if ( method_exists( $sms_provider, 'get_provider_slug' ) ) {
							$payload['provider'] = $sms_provider->get_provider_slug();
						}
					}
				}
				return $payload;
			}
		);

		// Organizer-phone resolution status — used by the SMS Notification tab to
		// warn admins when no phone can be found for the organizer despite an
		// organizer-bound SMS template being enabled. See
		// {@see Services\BookingSmsNotifier::resolve_organizer_phone()}.
		add_filter(
			'doublescale_booking_sms_organizer_phone_status',
			static function ( $payload, $event ) {
				$payload = (array) $payload;
				if ( ! $event ) {
					return $payload;
				}
				$calendar             = $event->calendar ?? null;
				[ $phone, $source ]   = Services\BookingSmsNotifier::resolve_organizer_phone_for( $event, $calendar );
				$payload['resolved']  = '' !== $phone;
				$payload['source']    = $source;
				return $payload;
			},
			10,
			2
		);
	}

	public function boot( Container $container ): void {
		if ( ! $this->free_booking_present() ) {
			return;
		}

		parent::boot( $container );

		// Resolve singletons to trigger their constructor's hook registrations
		// (BookingSmsNotifier subscribes to `doublescale_booking_*` lifecycle hooks,
		// WaitingListHandler registers its own AJAX/REST handlers).
		$container->get( Services\WaitingListHandler::class );
		$container->get( Services\BookingSmsNotifier::class );

		// Eagerly instantiate the Stripe booking gateway so its constructor's
		// `wp_ajax_*` and `doublescale_*` hook registrations always happen,
		// even on requests (like the public booking AJAX endpoints) that don't
		// otherwise apply the `doublescale_booking_payment_gateways` filter.
		// Without this, `doublescale_booking_init_stripe` AJAX returns `0`
		// because the handler was never bound.
		PaymentGateways\BookingStripeHandler::instance();

		// Defensive REST-side primer: free's Module::boot() already eagerly
		// instantiates integrations via the `doublescale_booking_integrations`
		// filter, but a REST request that bypasses module boot (e.g. headless
		// auth callbacks) won't trigger that. Prime them on `rest_api_init` so
		// integration REST routes always register.
		//
		// The Stripe gateway must also be primed here — its constructor binds
		// `doublescale_stripe_booking_event`, which is fired from the Stripe
		// webhook controller during REST requests. Without this primer, the
		// webhook arrives, signature verifies, action fires — but no listener
		// is bound, so the booking never flips to scheduled.
		add_action(
			'rest_api_init',
			static function () {
				$primer = array(
					Integrations\Apple\Integration::class,
					Integrations\Google\Integration::class,
					Integrations\Outlook\Integration::class,
					Integrations\Zoom\Integration::class,
					PaymentGateways\BookingStripeHandler::class,
				);
				foreach ( $primer as $class ) {
					if ( ! class_exists( $class ) || ! method_exists( $class, 'instance' ) ) {
						continue;
					}
					try {
						$class::instance();
					} catch ( \Throwable $e ) {
						doublescale_get_logger()->error(
							'Booking integration REST primer failed',
							array(
								'source' => 'booking-pro-module',
								'class'  => $class,
								'error'  => $e->getMessage(),
							)
						);
					}
				}
			},
			1
		);
	}
}
