<?php
/**
 * Typeform integration.
 *
 * Stores the personal access token only. Each form connection is configured
 * under Forms → SaaS Forms → Typeform.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Typeform;

use DoubleScale\Core\Managers\IntegrationsManager;
use DoubleScale\Pro\Modules\Integrations\Abstracts\Integration as Integration_Abstract;

defined( 'ABSPATH' ) || exit;

/**
 * Typeform integration class.
 */
class Integration extends Integration_Abstract {

	/**
	 * Webhook tag registered on Typeform forms.
	 */
	public const WEBHOOK_TAG = 'doublescale';

	/**
	 * @var string
	 */
	public $name = 'Typeform';

	/**
	 * @var string
	 */
	public $slug = 'typeform';

	/**
	 * @var string
	 */
	public $description = 'Connect your Typeform account with a personal access token.';

	/**
	 * @var bool
	 */
	public $is_pro = true;

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

		$access_token = $this->get_setting( 'access_token' );

		if ( empty( $access_token ) ) {
			return false;
		}

		$this->api = new Api( $access_token );

		return $this->api;
	}

	/**
	 * @param array $settings Settings.
	 * @return bool|\WP_Error
	 */
	public function validate( $settings ) {
		$access_token = $settings['access_token'] ?? '';

		if ( empty( $access_token ) ) {
			return new \WP_Error( 'invalid_settings', __( 'Personal access token is required.', 'doublescale' ) );
		}

		$api    = new Api( $access_token );
		$result = $api->get_forms();

		if ( empty( $result['success'] ) ) {
			return new \WP_Error(
				'invalid_token',
				__( 'Could not connect to Typeform. Check your personal access token.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * @return string
	 */
	public function get_webhook_url() {
		if ( defined( 'DOUBLESCALE_PUBLIC_REST_URL' ) && DOUBLESCALE_PUBLIC_REST_URL ) {
			return trailingslashit( DOUBLESCALE_PUBLIC_REST_URL ) . 'doublescale/v1/integrations/typeform/webhook';
		}

		return rest_url( 'doublescale/v1/integrations/typeform/webhook' );
	}
}

IntegrationsManager::instance()->register( new Integration() );
