<?php
/**
 * Stripe API wrapper.
 *
 * Thin adapter over `\DoubleScale\Pro\Vendor\Stripe\StripeClient`. Exists so RestController and the
 * Booking adapter have a single place to instantiate the client and trap
 * Stripe SDK exceptions into a uniform `[ 'success' => bool, 'data' => ... ]`
 * shape. Mirrors the Twilio `Api` shape for cross-integration consistency.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Stripe;

use DoubleScale\Pro\Vendor\Stripe\StripeClient;
use DoubleScale\Pro\Vendor\Stripe\Exception\ApiErrorException;

defined( 'ABSPATH' ) || exit;

class Api {

	/**
	 * @var StripeClient
	 */
	private $client;

	public function __construct( string $secret_key ) {
		$this->client = new StripeClient( $secret_key );
	}

	public function client(): StripeClient {
		return $this->client;
	}

	/**
	 * Round-trip the Stripe API to confirm the secret key is valid.
	 *
	 * @return array{success:bool,data?:mixed,message?:string}
	 */
	public function retrieve_account(): array {
		try {
			$account = $this->client->accounts->retrieve();
			return array(
				'success' => true,
				'data'    => array(
					'id'      => $account->id,
					'country' => $account->country ?? null,
					'email'   => $account->email ?? null,
				),
			);
		} catch ( ApiErrorException $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		} catch ( \Throwable $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Create a Stripe webhook endpoint that points back at this site's
	 * `/doublescale/v1/integrations/stripe/webhook` REST route. Returns
	 * `[ 'id' => 'we_…', 'secret' => 'whsec_…' ]` on success so callers
	 * can persist them.
	 *
	 * @return array{success:bool,id?:string,secret?:string,message?:string}
	 */
	public function create_webhook_endpoint( string $url, array $events ): array {
		try {
			$endpoint = $this->client->webhookEndpoints->create(
				array(
					'url'            => $url,
					'enabled_events' => $events,
				)
			);
			return array(
				'success' => true,
				'id'      => $endpoint->id,
				'secret'  => $endpoint->secret,
			);
		} catch ( ApiErrorException $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}
}
