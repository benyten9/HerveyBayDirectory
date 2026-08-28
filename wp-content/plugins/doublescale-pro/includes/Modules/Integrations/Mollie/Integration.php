<?php
/**
 * Mollie global integration.
 *
 * @package DoubleScale\Pro\Modules\Integrations\Mollie
 */

namespace DoubleScale\Pro\Modules\Integrations\Mollie;

use DoubleScale\Pro\Modules\Integrations\Abstracts\Integration as Integration_Abstract;

defined( 'ABSPATH' ) || exit;

/**
 * Integration class.
 */
class Integration extends Integration_Abstract {

	public $name = 'Mollie';

	public $slug = 'mollie';

	public $description = 'Accept iDEAL, Bancontact, SEPA and card payments for invoices via Mollie.';

	/**
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Accept iDEAL, Bancontact, SEPA and card payments for invoices via Mollie.', 'doublescale' );
	}

	public $is_pro = false;

	public $show_in_catalog = true;

	public $catalog_category = 'payment';

	public $option_name = 'mollie';

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
	 * Resolve credentials for a mode. Unlike PayPal/Square there is no separate
	 * mode field — Mollie encodes it in the key prefix.
	 *
	 * @param string|null $mode `test` or `live`.
	 * @return array|false
	 */
	public function get_mode_settings( $mode = null ) {
		$settings = $this->get_settings();
		$mode     = $mode ?? ( $settings['mode'] ?? null );

		if ( ! in_array( $mode, array( 'test', 'live' ), true ) ) {
			if ( ! empty( $settings['test_api_key'] ) ) {
				$mode = 'test';
			} elseif ( ! empty( $settings['live_api_key'] ) ) {
				$mode = 'live';
			} else {
				return false;
			}
		}

		$api_key = (string) ( $settings[ "{$mode}_api_key" ] ?? '' );
		if ( '' === $api_key ) {
			return false;
		}

		// A live key pasted into the test field (or vice versa) would silently
		// charge real money — refuse the mismatch instead.
		$key_mode = Api::mode_from_key( $api_key );
		if ( '' !== $key_mode && $key_mode !== $mode ) {
			return false;
		}

		return array(
			'mode'    => $mode,
			'api_key' => $api_key,
		);
	}

	/**
	 * @return bool
	 */
	public function is_configured(): bool {
		return false !== $this->get_mode_settings();
	}

	/**
	 * Mollie uses a dedicated Api client, not IntegrationApi.
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

		$this->api = new Api( (string) $mode_settings['api_key'] );

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

		$api_key = (string) ( $settings[ "{$mode}_api_key" ] ?? '' );
		if ( '' === $api_key ) {
			return new \WP_Error( 'missing_credentials', __( 'An API key is required for the selected mode.', 'doublescale' ) );
		}

		$key_mode = Api::mode_from_key( $api_key );
		if ( '' === $key_mode ) {
			return new \WP_Error(
				'invalid_api_key',
				__( 'Mollie API keys start with test_ or live_.', 'doublescale' )
			);
		}
		if ( $key_mode !== $mode ) {
			return new \WP_Error(
				'api_key_mode_mismatch',
				sprintf(
					/* translators: 1: mode from the key prefix, 2: selected mode */
					__( 'This is a %1$s key but %2$s mode is selected. Paste it into the %1$s field instead.', 'doublescale' ),
					$key_mode,
					$mode
				)
			);
		}

		$result = ( new Api( $api_key ) )->list_methods();
		if ( ! $result['success'] ) {
			doublescale_get_logger()->warning(
				'Mollie credentials validation failed',
				array(
					'code'    => 'mollie_validation_failed',
					'mode'    => $mode,
					'message' => $result['message'] ?? '',
				)
			);
			return new \WP_Error( 'mollie_connection_failed', $result['message'] ?? __( 'Failed to connect to Mollie.', 'doublescale' ) );
		}

		return true;
	}

	/**
	 * Catalog icon.
	 *
	 * @return string
	 */
	public function get_icon_url(): string {
		return $this->plugin_asset_url( 'assets/images/mollie/mollie.svg' );
	}
}
