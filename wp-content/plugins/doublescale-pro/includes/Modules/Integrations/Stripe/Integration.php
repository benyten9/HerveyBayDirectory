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

	/**
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Accept credit-card payments through Stripe. Used by the booking module for paid events; available CRM-wide for future deal/invoice flows.', 'doublescale' );
	}

	public $is_pro = false;

	public $show_in_catalog = true;

	public $catalog_category = 'payment';

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

	/**
	 * The publishable key Stripe.js may load, or '' when the saved one would be
	 * refused by Stripe.js or must not reach a browser (a secret key).
	 *
	 * @return string
	 */
	public function get_publishable_key(): string {
		$mode_settings = $this->get_mode_settings();
		if ( ! $mode_settings ) {
			return '';
		}
		$key = (string) $mode_settings['publishable_key'];
		return null === self::key_problem( 'publishable', $key, (string) $mode_settings['mode'] ) ? $key : '';
	}

	/**
	 * Why the saved publishable key cannot be used, or '' when it can (or when
	 * Stripe is not set up at all).
	 *
	 * @return string
	 */
	public function publishable_key_problem(): string {
		$mode_settings = $this->get_mode_settings();
		if ( ! $mode_settings ) {
			return '';
		}
		$problem = self::key_problem( 'publishable', (string) $mode_settings['publishable_key'], (string) $mode_settings['mode'] );
		return $problem ? $problem['message'] : '';
	}

	/**
	 * Why a key does not belong in a Stripe key field, or null when it does.
	 *
	 * The publishable key is sent to every customer on a payment page and
	 * Stripe.js accepts nothing else: a restricted key there leaves a Pay button
	 * that never works, and a secret key would be published.
	 *
	 * @param string $field `publishable` or `secret`.
	 * @param string $key   Key as saved.
	 * @param string $mode  `sandbox` or `live`.
	 * @return array{code: string, message: string}|null
	 */
	public static function key_problem( string $field, string $key, string $mode ): ?array {
		$expected_mode = 'live' === $mode ? 'live' : 'test';
		$kind          = '';
		$key_mode      = '';
		if ( preg_match( '/^(pk|sk|rk)_(test|live)_/', trim( $key ), $matches ) ) {
			$kind     = $matches[1];
			$key_mode = $matches[2];
		}

		if ( 'publishable' === $field ) {
			$code = 'stripe_invalid_publishable_key';
			if ( 'rk' === $kind ) {
				return array(
					'code'    => $code,
					'message' => sprintf(
						/* translators: %s: expected key prefix, e.g. pk_test_ */
						__( 'This is a restricted key. Stripe only accepts your publishable key here. It starts with %s and you can find it in your Stripe dashboard under Developers → API keys.', 'doublescale' ),
						'pk_' . $expected_mode . '_'
					),
				);
			}
			if ( 'sk' === $kind ) {
				return array(
					'code'    => $code,
					'message' => sprintf(
						/* translators: %s: expected key prefix, e.g. pk_test_ */
						__( 'This is a secret key. Never paste it here: this key is shown to every customer on the payment page. Use your publishable key instead. It starts with %s.', 'doublescale' ),
						'pk_' . $expected_mode . '_'
					),
				);
			}
			if ( 'pk' !== $kind ) {
				return array(
					'code'    => $code,
					'message' => sprintf(
						/* translators: %s: expected key prefix, e.g. pk_test_ */
						__( 'This is not a Stripe publishable key. It should start with %s.', 'doublescale' ),
						'pk_' . $expected_mode . '_'
					),
				);
			}
		} else {
			$code = 'stripe_invalid_secret_key';
			if ( 'pk' === $kind ) {
				return array(
					'code'    => $code,
					'message' => sprintf(
						/* translators: 1: secret key prefix, 2: restricted key prefix */
						__( 'This is a publishable key. The Secret Key field needs your secret key (%1$s) or a restricted key (%2$s).', 'doublescale' ),
						'sk_' . $expected_mode . '_',
						'rk_' . $expected_mode . '_'
					),
				);
			}
			if ( '' === $kind ) {
				return array(
					'code'    => $code,
					'message' => sprintf(
						/* translators: 1: secret key prefix, 2: restricted key prefix */
						__( 'This is not a Stripe secret key. It should start with %1$s or %2$s.', 'doublescale' ),
						'sk_' . $expected_mode . '_',
						'rk_' . $expected_mode . '_'
					),
				);
			}
		}

		if ( $key_mode !== $expected_mode ) {
			return array(
				'code'    => 'stripe_key_mode_mismatch',
				'message' => 'live' === $key_mode
					? __( 'This is a live key but Sandbox mode is selected. Switch to Live mode, or paste your test key instead.', 'doublescale' )
					: __( 'This is a test key but Live mode is selected. Switch to Sandbox mode, or paste your live key instead.', 'doublescale' ),
			);
		}

		return null;
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

		// Checked before Stripe is called: the account lookup only proves the
		// secret key, never the key customers' browsers receive.
		$publishable_key = (string) ( $settings[ "{$mode}_publishable_key" ] ?? '' );
		$problem         = '' === $publishable_key ? null : self::key_problem( 'publishable', $publishable_key, $mode );
		if ( null === $problem ) {
			$problem = self::key_problem( 'secret', (string) $secret_key, $mode );
		}
		if ( null !== $problem ) {
			return new \WP_Error( $problem['code'], $problem['message'], array( 'status' => 400 ) );
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

	/**
	 * Catalog icon.
	 *
	 * @return string
	 */
	public function get_icon_url(): string {
		return $this->plugin_asset_url( 'assets/images/stripe/stripe.png' );
	}
}
