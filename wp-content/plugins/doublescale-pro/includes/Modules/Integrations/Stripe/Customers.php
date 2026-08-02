<?php
/**
 * Stripe customer lookup / create.
 *
 * Lifted from `Modules/Booking/PaymentGateways/Stripe/Customers.php`. Now
 * resolves credentials from the global Stripe integration instead of the old
 * Booking-side gateway singleton.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Stripe;

use DoubleScale\Pro\Vendor\Stripe\StripeClient;

defined( 'ABSPATH' ) || exit;

class Customers {

	/**
	 * @var StripeClient
	 */
	private $client;

	/**
	 * @param array|null $mode_settings Pre-resolved credentials. If null, asks
	 *                                  the global integration for the active
	 *                                  mode's credentials.
	 */
	public function __construct( ?array $mode_settings = null ) {
		if ( null === $mode_settings ) {
			$mode_settings = Integration::instance()->get_mode_settings();
		}

		$this->client = new StripeClient( $mode_settings['secret_key'] );
	}

	/**
	 * Find an existing customer by email. Returns Stripe customer id or null.
	 */
	public function get( string $email ): ?string {
		$customers = $this->client->customers->all( array( 'email' => $email ) );
		return $customers->data[0]->id ?? null;
	}

	public function create( ?string $name, ?string $email ): string {
		$customer = $this->client->customers->create(
			array_filter(
				array(
					'name'  => $name,
					'email' => $email,
				),
				static fn ( $v ) => null !== $v && '' !== $v
			)
		);
		return $customer->id;
	}

	/**
	 * Resolve a Stripe customer by email, creating one only when needed.
	 *
	 * Requires a valid email. The previous behaviour silently created a new
	 * (unlinked, unreachable) customer on every retry for bookings without
	 * an email, which leaks ghost customers into Stripe and breaks the
	 * "one Stripe customer per contact" invariant. Callers must provide a
	 * valid email — booking contacts always have one by validation, so
	 * this is the right place to enforce it.
	 *
	 * @throws \InvalidArgumentException When the email is missing or invalid.
	 */
	public function get_or_create( ?string $name, ?string $email ): string {
		if ( ! $email || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			throw new \InvalidArgumentException( 'A valid email is required to create a Stripe customer.' );
		}
		$existing = $this->get( $email );
		if ( $existing ) {
			return $existing;
		}
		return $this->create( $name, $email );
	}
}
