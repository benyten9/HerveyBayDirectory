<?php
/**
 * Stripe global integration.
 *
 * Owns the Stripe credentials shared across the CRM. Other modules (Booking
 * today, Deals/Invoicing later) consume this integration the same way
 * `BookingSmsNotifier` consumes the Twilio integration: read credentials,
 * build a service, charge.
 *
 * Settings shape mirrors QuillBooking-pro so the storage format is familiar:
 *
 *   [
 *     'mode' => 'sandbox'|'live',
 *     'sandbox_publishable_key' => '', 'sandbox_secret_key' => '',
 *     'sandbox_webhook_id'      => '', 'sandbox_webhook_secret' => '',
 *     'live_publishable_key'    => '', 'live_secret_key' => '',
 *     'live_webhook_id'         => '', 'live_webhook_secret' => '',
 *   ]
 *
 * One mode active at a time. No multi-account / Stripe Connect.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Stripe;

use DoubleScale\Pro\Modules\Integrations\Abstracts\Integration as Integration_Abstract;

defined( 'ABSPATH' ) || exit;

class Integration extends Integration_Abstract {

	public $name = 'Stripe';

	public $slug = 'stripe';

	public $description = 'Accept credit-card payments through Stripe. Used by the booking module for paid events; available CRM-wide for future deal/invoice flows.';

	public $is_pro = false;

	public $option_name = 'stripe';

	protected static $classes = array(
		'rest_controller' => RestController::class,
	);

	/**
	 * Singleton accessor — Booking + future modules resolve credentials through this.
	 */
	public static function instance(): self {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}
		return $instance;
	}

	/**
	 * Resolve credentials for the active mode (or a specific mode if requested).
	 *
	 * Returns an associative array with `mode`, `publishable_key`, `secret_key`,
	 * `webhook_id`, `webhook_secret` — or `false` when credentials are missing
	 * for the requested mode. Callers should treat `false` as "Stripe is not
	 * configured."
	 *
	 * @param string|null $mode `sandbox` or `live`. Defaults to active mode.
	 * @return array|false
	 */
	public function get_mode_settings( $mode = null ) {
		$settings = $this->get_settings();
		$mode     = $mode ?? ( $settings['mode'] ?? null );

		if ( ! in_array( $mode, array( 'sandbox', 'live' ), true ) ) {
			// Legacy saves may omit `mode` when only keys were pasted. Infer from
			// whichever mode has a secret key so local Stripe CLI setups work.
			if ( ! empty( $settings['sandbox_secret_key'] ) ) {
				$mode = 'sandbox';
			} elseif ( ! empty( $settings['live_secret_key'] ) ) {
				$mode = 'live';
			} else {
				return false;
			}
		}

		$result = array( 'mode' => $mode );
		foreach ( array( 'publishable_key', 'secret_key', 'webhook_id', 'webhook_secret' ) as $key ) {
			$value = $settings[ "{$mode}_{$key}" ] ?? '';
			if ( '' === $value && in_array( $key, array( 'publishable_key', 'secret_key' ), true ) ) {
				return false;
			}
			$result[ $key ] = $value;
		}

		return $result;
	}

	public function is_configured(): bool {
		return false !== $this->get_mode_settings();
	}

	/**
	 * Payment gateways use a dedicated Api client, not IntegrationApi.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return $this->is_configured();
	}

	public function get_publishable_key(): string {
		$mode_settings = $this->get_mode_settings();
		return $mode_settings ? (string) $mode_settings['publishable_key'] : '';
	}

	/**
	 * Build a configured API client for the active mode. Used by callers that
	 * need to make Stripe API calls (creating PIs, retrieving charges, etc.).
	 *
	 * @return Api|false
	 */
	public function connect() {
		if ( $this->api instanceof Api ) {
			return $this->api;
		}

		$mode_settings = $this->get_mode_settings();
		if ( ! $mode_settings ) {
			return false;
		}

		$this->api = new Api( $mode_settings['secret_key'] );
		return $this->api;
	}

	/**
	 * Validate a candidate settings payload by hitting Stripe's `Account::retrieve()`.
	 *
	 * Used by RestController on settings save to give the operator immediate
	 * "credentials work / don't work" feedback. Mirrors the Twilio
	 * connection-test flow.
	 */
	public function validate( $settings ) {
		$mode = $settings['mode'] ?? 'sandbox';
		if ( ! in_array( $mode, array( 'sandbox', 'live' ), true ) ) {
			return new \WP_Error( 'invalid_mode', __( 'Mode must be sandbox or live.', 'doublescale' ) );
		}

		$secret_key = $settings[ "{$mode}_secret_key" ] ?? '';
		if ( '' === $secret_key ) {
			return new \WP_Error( 'missing_secret', __( 'Secret key is required for the selected mode.', 'doublescale' ) );
		}

		$api    = new Api( $secret_key );
		$result = $api->retrieve_account();

		if ( ! $result['success'] ) {
			doublescale_get_logger()->warning(
				'Stripe credentials validation failed',
				array(
					'code'    => 'stripe_validation_failed',
					'mode'    => $mode,
					'message' => $result['message'] ?? '',
				)
			);
			return new \WP_Error( 'stripe_connection_failed', $result['message'] ?? __( 'Failed to connect to Stripe.', 'doublescale' ) );
		}

		return true;
	}
}
