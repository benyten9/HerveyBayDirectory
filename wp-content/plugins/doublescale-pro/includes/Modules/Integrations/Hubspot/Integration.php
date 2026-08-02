<?php
/**
 * Class Hubspot
 *
 * This class is responsible for handling the Hubspot integration
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Hubspot;

use DoubleScale\Pro\Modules\Integrations\Abstracts\Integration as Integration_Abstract;
use DoubleScale\Core\Managers\IntegrationsManager;

/**
 * Hubspot class
 */
class Integration extends Integration_Abstract {

	/**
	 * Integration Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Hubspot';

	/**
	 * Integration Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'hubspot';

	/**
	 * Integration Description
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $description = 'Hubspot Integration';

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

		$access_token = $this->get_setting( 'access_token' );

		if ( empty( $access_token ) ) {
			return false;
		}

		$this->api = new Api( $access_token );

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
		$access_token = $settings['access_token'] ?? '';

		if ( empty( $access_token ) ) {
			return new \WP_Error( 'invalid_settings', __( 'Api Key is required.', 'doublescale') );
		}

		$api    = new Api( $access_token );
		$result = $api->get_companies();
		if ( $result['success'] ) {
			return true;
		} else {
			return false;
		}
	}
}

IntegrationsManager::instance()->register( new Integration() );
