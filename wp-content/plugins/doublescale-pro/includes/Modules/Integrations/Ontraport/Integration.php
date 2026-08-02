<?php
/**
 * Class Ontraport
 *
 * This class is responsible for handling the Ontraport integration
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Ontraport;

use DoubleScale\Pro\Modules\Integrations\Abstracts\Integration as Integration_Abstract;
use DoubleScale\Core\Managers\IntegrationsManager;

/**
 * Ontraport class
 */
class Integration extends Integration_Abstract {

	/**
	 * Integration Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Ontraport';

	/**
	 * Integration Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'ontraport';

	/**
	 * Integration Description
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $description = 'Ontraport Integration';

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

		$api_key = $this->get_setting( 'api_key' );
		$app_id  = $this->get_setting( 'app_id' );

		if ( empty( $api_key ) || empty( $app_id ) ) {
			return false;
		}

		$this->api = new Api( $api_key, $app_id );

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
		$api_key = $settings['api_key'] ?? '';
		$app_id  = $settings['app_id'] ?? '';

		if ( empty( $api_key ) ) {
			return new \WP_Error( 'invalid_settings', __( 'Api Key is required.', 'doublescale') );
		}

		if ( empty( $app_id ) ) {
			return new \WP_Error( 'invalid_settings', __( 'App ID is required.', 'doublescale') );
		}

		$api    = new Api( $api_key, $app_id );
		$result = $api->get_info();
		if ( $result['success'] ) {
			return true;
		} else {
			return false;
		}
	}
}

IntegrationsManager::instance()->register( new Integration() );
