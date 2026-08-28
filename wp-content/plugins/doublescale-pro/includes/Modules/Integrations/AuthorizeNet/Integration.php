<?php
/**
 * Authorize.Net global integration.
 *
 * @package DoubleScale\Pro\Modules\Integrations\AuthorizeNet
 */

namespace DoubleScale\Pro\Modules\Integrations\AuthorizeNet;

use DoubleScale\Pro\Modules\Integrations\Abstracts\Integration as Integration_Abstract;

defined( 'ABSPATH' ) || exit;

/**
 * Integration class.
 */
class Integration extends Integration_Abstract {

	public $name = 'Authorize.Net';

	public $slug = 'authorize_net';

	public $description = 'Accept card payments for invoices via Authorize.Net Accept Hosted.';

	/**
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Accept card payments for invoices via Authorize.Net Accept Hosted.', 'doublescale' );
	}

	public $is_pro = false;

	public $show_in_catalog = true;

	public $catalog_category = 'payment';

	public $option_name = 'authorize_net';

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
	 * @param string|null $mode `sandbox` or `production`.
	 * @return array|false
	 */
	public function get_mode_settings( $mode = null ) {
		$settings = $this->get_settings();
		$mode     = $mode ?? ( $settings['mode'] ?? null );

		if ( ! in_array( $mode, array( 'sandbox', 'production' ), true ) ) {
			if ( ! empty( $settings['sandbox_transaction_key'] ) ) {
				$mode = 'sandbox';
			} elseif ( ! empty( $settings['production_transaction_key'] ) ) {
				$mode = 'production';
			} else {
				return false;
			}
		}

		$login_id        = (string) ( $settings[ "{$mode}_login_id" ] ?? '' );
		$transaction_key = (string) ( $settings[ "{$mode}_transaction_key" ] ?? '' );

		if ( '' === $login_id || '' === $transaction_key ) {
			return false;
		}

		return array(
			'mode'            => $mode,
			'login_id'        => $login_id,
			'transaction_key' => $transaction_key,
			'signature_key'   => (string) ( $settings[ "{$mode}_signature_key" ] ?? '' ),
		);
	}

	/**
	 * @return bool
	 */
	public function is_configured(): bool {
		return false !== $this->get_mode_settings();
	}

	/**
	 * Authorize.Net uses a dedicated Api client, not IntegrationApi.
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
			(string) $mode_settings['login_id'],
			(string) $mode_settings['transaction_key'],
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
		if ( ! in_array( $mode, array( 'sandbox', 'production' ), true ) ) {
			return new \WP_Error( 'invalid_mode', __( 'Mode must be sandbox or production.', 'doublescale' ) );
		}

		$login_id        = (string) ( $settings[ "{$mode}_login_id" ] ?? '' );
		$transaction_key = (string) ( $settings[ "{$mode}_transaction_key" ] ?? '' );

		if ( '' === $login_id || '' === $transaction_key ) {
			return new \WP_Error(
				'missing_credentials',
				__( 'API login ID and transaction key are required for the selected mode.', 'doublescale' )
			);
		}

		$result = ( new Api( $login_id, $transaction_key, $mode ) )->authenticate();
		if ( ! $result['success'] ) {
			doublescale_get_logger()->warning(
				'Authorize.Net credentials validation failed',
				array(
					'code'    => 'authorize_net_validation_failed',
					'mode'    => $mode,
					'message' => $result['message'] ?? '',
				)
			);
			return new \WP_Error(
				'authorize_net_connection_failed',
				$result['message'] ?? __( 'Failed to connect to Authorize.Net.', 'doublescale' )
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
		return $this->plugin_asset_url( 'assets/images/authorize-net/authorize-net.svg' );
	}
}
