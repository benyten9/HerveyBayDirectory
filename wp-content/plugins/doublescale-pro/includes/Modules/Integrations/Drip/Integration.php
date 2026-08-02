<?php
/**
 * Class Drip
 *
 * This class is responsible for handling the Drip integration
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Drip;

use DoubleScale\Pro\Modules\Integrations\Abstracts\Integration as Integration_Abstract;
use DoubleScale\Core\Managers\IntegrationsManager;

/**
 * Drip class
 */
class Integration extends Integration_Abstract {

	/**
	 * Integration Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Drip';

	/**
	 * Integration Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'drip';

	/**
	 * Integration Description
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $description = 'Drip Integration';

	/**
	 * Class names
	 *
	 * @var array
	 */
	protected static $classes = array(
		'remote_data'     => RemoteData::class,
		'rest_controller' => RestController::class,
	);

	/**
	 * Connect the integration
	 *
	 * @since 1.0.0
	 *
	 * @return bool|Api
	 */
	public function connect() {
		if ( $this->api instanceof Api ) {
			return $this->api;
		}

		$api_token  = $this->get_setting( 'api_token' );
		$account_id = $this->get_setting( 'account_id' );

		if ( empty( $api_token ) ) {
			return false;
		}

		$this->api = new Api( $api_token, $account_id );

		return $this->api;
	}

	/**
	 * Validate.
	 *
	 * @param array $settings Settings.
	 *
	 * @return bool
	 */
	public function validate( $settings ) {
		$api_token = $settings['api_token'] ?? '';

		if ( empty( $api_token ) ) {
			return new \WP_Error( 'invalid_settings', __( 'Api Key is required.', 'doublescale') );
		}

		$api    = new Api( $api_token, '' );
		$result = $api->get_accounts();
		if ( $result['success'] ) {
			return true;
		} else {
			return false;
		}
	}
}

IntegrationsManager::instance()->register( new Integration() );
