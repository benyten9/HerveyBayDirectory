<?php
/**
 * Jotform integration.
 *
 * Stores the API key only. Each form connection is configured under
 * Forms → SaaS Forms → Jotform.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Jotform;

use DoubleScale\Core\Managers\IntegrationsManager;
use DoubleScale\Pro\Modules\Integrations\Abstracts\Integration as Integration_Abstract;

defined( 'ABSPATH' ) || exit;

/**
 * Jotform integration class.
 */
class Integration extends Integration_Abstract {

	/**
	 * @var string
	 */
	public $name = 'Jotform';

	/**
	 * @var string
	 */
	public $slug = 'jotform';

	/**
	 * @var string
	 */
	public $description = 'Connect your Jotform account with an API key.';

	/**
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Connect your Jotform account with an API key.', 'doublescale' );
	}

	/**
	 * @var bool
	 */
	public $is_pro = true;

	/**
	 * Show on Integrations catalog.
	 *
	 * @var bool
	 */
	public $show_in_catalog = true;

	public $catalog_category = 'forms';

	/**
	 * @var array
	 */
	protected static $classes = array(
		'rest_controller' => RestController::class,
	);

	/**
	 * @return Api|false
	 */
	public function connect() {
		if ( $this->api instanceof Api ) {
			return $this->api;
		}

		$api_key = $this->get_setting( 'api_key' );

		if ( empty( $api_key ) ) {
			return false;
		}

		$this->api = new Api( $api_key );

		return $this->api;
	}

	/**
	 * @param array $settings Settings.
	 * @return bool|\WP_Error
	 */
	public function validate( $settings ) {
		$api_key = $settings['api_key'] ?? '';

		if ( empty( $api_key ) ) {
			return new \WP_Error( 'invalid_settings', __( 'API key is required.', 'doublescale' ) );
		}

		$api    = new Api( $api_key );
		$result = $api->get_forms();

		if ( empty( $result['success'] ) ) {
			return new \WP_Error(
				'invalid_api_key',
				__( 'Could not connect to Jotform. Check your API key.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Inbound webhook URL. The stored webhook token is embedded in the path so
	 * we can authenticate callbacks (Jotform does not sign its webhooks).
	 *
	 * @return string
	 */
	public function get_webhook_url() {
		$token = (string) $this->get_setting( 'webhook_token' );
		$path  = 'doublescale/v1/integrations/jotform/webhook/' . rawurlencode( $token );

		if ( defined( 'DOUBLESCALE_PUBLIC_REST_URL' ) && DOUBLESCALE_PUBLIC_REST_URL ) {
			return trailingslashit( DOUBLESCALE_PUBLIC_REST_URL ) . $path;
		}

		return rest_url( $path );
	}

	/**
	 * Catalog icon.
	 *
	 * @return string
	 */
	public function get_icon_url(): string {
		return $this->plugin_asset_url( 'assets/images/jotform/jotform.png' );
	}
}

IntegrationsManager::instance()->register( new Integration() );
