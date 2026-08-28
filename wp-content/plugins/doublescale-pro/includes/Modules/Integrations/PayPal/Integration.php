<?php
/**
 * PayPal global integration.
 *
 * @package DoubleScale\Pro\Modules\Integrations\PayPal
 */

namespace DoubleScale\Pro\Modules\Integrations\PayPal;

use DoubleScale\Pro\Modules\Integrations\Abstracts\Integration as Integration_Abstract;

defined( 'ABSPATH' ) || exit;

/**
 * Integration class.
 */
class Integration extends Integration_Abstract {

	public $name = 'PayPal';

	public $slug = 'paypal';

	public $description = 'Accept PayPal payments for invoices. Configure sandbox or live REST app credentials.';

	/**
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Accept PayPal payments for invoices. Configure sandbox or live REST app credentials.', 'doublescale' );
	}

	public $is_pro = false;

	public $show_in_catalog = true;

	public $catalog_category = 'payment';

	public $option_name = 'paypal';

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
	 * @param string|null $mode `sandbox` or `live`.
	 * @return array|false
	 */
	public function get_mode_settings( $mode = null ) {
		$settings = $this->get_settings();
		$mode     = $mode ?? ( $settings['mode'] ?? null );

		if ( ! in_array( $mode, array( 'sandbox', 'live' ), true ) ) {
			if ( ! empty( $settings['sandbox_secret'] ) ) {
				$mode = 'sandbox';
			} elseif ( ! empty( $settings['live_secret'] ) ) {
				$mode = 'live';
			} else {
				return false;
			}
		}

		$result = array( 'mode' => $mode );
		foreach ( array( 'client_id', 'secret', 'webhook_id' ) as $key ) {
			$value = $settings[ "{$mode}_{$key}" ] ?? '';
			if ( '' === $value && in_array( $key, array( 'client_id', 'secret' ), true ) ) {
				return false;
			}
			$result[ $key ] = $value;
		}

		return $result;
	}

	/**
	 * @return bool
	 */
	public function is_configured(): bool {
		return false !== $this->get_mode_settings();
	}

	/**
	 * PayPal uses a dedicated Api client, not IntegrationApi.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return $this->is_configured();
	}

	/**
	 * @return string
	 */
	public function get_client_id(): string {
		$mode_settings = $this->get_mode_settings();
		return $mode_settings ? (string) $mode_settings['client_id'] : '';
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
			(string) $mode_settings['client_id'],
			(string) $mode_settings['secret'],
			(string) $mode_settings['mode']
		);

		return $this->api;
	}

	/**
	 * @param array $settings Candidate settings.
	 * @return true|\WP_Error
	 */
	public function validate( $settings ) {
		$mode = $settings['mode'] ?? 'sandbox';
		if ( ! in_array( $mode, array( 'sandbox', 'live' ), true ) ) {
			return new \WP_Error( 'invalid_mode', __( 'Mode must be sandbox or live.', 'doublescale' ) );
		}

		$client_id = $settings[ "{$mode}_client_id" ] ?? '';
		$secret    = $settings[ "{$mode}_secret" ] ?? '';
		if ( '' === $client_id || '' === $secret ) {
			return new \WP_Error( 'missing_credentials', __( 'Client ID and secret are required for the selected mode.', 'doublescale' ) );
		}

		$api    = new Api( $client_id, $secret, $mode );
		$result = $api->get_access_token();

		if ( ! $result['success'] ) {
			doublescale_get_logger()->warning(
				'PayPal credentials validation failed',
				array(
					'code'    => 'paypal_validation_failed',
					'mode'    => $mode,
					'message' => $result['message'] ?? '',
				)
			);
			return new \WP_Error( 'paypal_connection_failed', $result['message'] ?? __( 'Failed to connect to PayPal.', 'doublescale' ) );
		}

		return true;
	}

	/**
	 * Catalog icon.
	 *
	 * @return string
	 */
	public function get_icon_url(): string {
		return $this->plugin_asset_url( 'assets/images/paypal/paypal.png' );
	}
}
