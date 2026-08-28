<?php
/**
 * Razorpay global integration.
 *
 * @package DoubleScale\Pro\Modules\Integrations\Razorpay
 */

namespace DoubleScale\Pro\Modules\Integrations\Razorpay;

use DoubleScale\Pro\Modules\Integrations\Abstracts\Integration as Integration_Abstract;

defined( 'ABSPATH' ) || exit;

/**
 * Integration class.
 */
class Integration extends Integration_Abstract {

	public $name = 'Razorpay';

	public $slug = 'razorpay';

	public $description = 'Accept UPI, cards, netbanking and wallet payments for invoices via Razorpay.';

	/**
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Accept UPI, cards, netbanking and wallet payments for invoices via Razorpay.', 'doublescale' );
	}

	public $is_pro = false;

	public $show_in_catalog = true;

	public $catalog_category = 'payment';

	public $option_name = 'razorpay';

	protected static $classes = array(
		'rest_controller' => RestController::class,
	);

	/**
	 * @return self
	 */
	public static function instance(): self {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}
		return $instance;
	}

	/**
	 * Resolve credentials for a mode, falling back to whichever is populated.
	 *
	 * @param string|null $mode `test` or `live`.
	 * @return array|false
	 */
	public function get_mode_settings( $mode = null ) {
		$settings = $this->get_settings();
		$mode     = $mode ?? ( $settings['mode'] ?? null );

		if ( ! in_array( $mode, array( 'test', 'live' ), true ) ) {
			if ( ! empty( $settings['test_key_secret'] ) ) {
				$mode = 'test';
			} elseif ( ! empty( $settings['live_key_secret'] ) ) {
				$mode = 'live';
			} else {
				return false;
			}
		}

		$key_id     = (string) ( $settings[ "{$mode}_key_id" ] ?? '' );
		$key_secret = (string) ( $settings[ "{$mode}_key_secret" ] ?? '' );

		if ( '' === $key_id || '' === $key_secret ) {
			return false;
		}

		// A live key pasted into the test field (or vice versa) would silently
		// charge real money — refuse the mismatch instead.
		$key_mode = Api::mode_from_key( $key_id );
		if ( '' !== $key_mode && $key_mode !== $mode ) {
			return false;
		}

		return array(
			'mode'           => $mode,
			'key_id'         => $key_id,
			'key_secret'     => $key_secret,
			'webhook_secret' => (string) ( $settings[ "{$mode}_webhook_secret" ] ?? '' ),
		);
	}

	/**
	 * @return bool
	 */
	public function is_configured(): bool {
		return false !== $this->get_mode_settings();
	}

	/**
	 * Razorpay uses a dedicated Api client, not IntegrationApi.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return $this->is_configured();
	}

	/**
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

		$this->api = new Api(
			(string) $mode_settings['key_id'],
			(string) $mode_settings['key_secret']
		);

		return $this->api;
	}

	/**
	 * @param array $settings Candidate settings.
	 * @return true|\WP_Error
	 */
	public function validate( $settings ) {
		$mode = $settings['mode'] ?? 'test';
		if ( ! in_array( $mode, array( 'test', 'live' ), true ) ) {
			return new \WP_Error( 'invalid_mode', __( 'Mode must be test or live.', 'doublescale' ) );
		}

		$key_id     = (string) ( $settings[ "{$mode}_key_id" ] ?? '' );
		$key_secret = (string) ( $settings[ "{$mode}_key_secret" ] ?? '' );

		if ( '' === $key_id || '' === $key_secret ) {
			return new \WP_Error(
				'missing_credentials',
				__( 'Key ID and key secret are required for the selected mode.', 'doublescale' )
			);
		}

		$key_mode = Api::mode_from_key( $key_id );
		if ( '' === $key_mode ) {
			return new \WP_Error(
				'invalid_api_key',
				__( 'Razorpay key IDs start with rzp_test_ or rzp_live_.', 'doublescale' )
			);
		}
		if ( $key_mode !== $mode ) {
			return new \WP_Error(
				'api_key_mode_mismatch',
				sprintf(
					/* translators: 1: mode from the key prefix, 2: selected mode */
					__( 'This is a %1$s key but %2$s mode is selected. Paste it into the %1$s fields instead.', 'doublescale' ),
					$key_mode,
					$mode
				)
			);
		}

		$result = ( new Api( $key_id, $key_secret ) )->verify_credentials();
		if ( ! $result['success'] ) {
			doublescale_get_logger()->warning(
				'Razorpay credentials validation failed',
				array(
					'code'    => 'razorpay_validation_failed',
					'mode'    => $mode,
					'message' => $result['message'] ?? '',
				)
			);
			return new \WP_Error(
				'razorpay_connection_failed',
				$result['message'] ?? __( 'Failed to connect to Razorpay.', 'doublescale' )
			);
		}

		return true;
	}

	/**
	 * Catalog icon.
	 *
	 * @return string
	 */
	public function get_icon_url(): string {
		return $this->plugin_asset_url( 'assets/images/razorpay/razorpay.svg' );
	}
}
