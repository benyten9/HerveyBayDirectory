<?php
/**
 * Class ActiveCampaign
 *
 * This class is responsible for handling the ActiveCampaign integration
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Activecampaign;

use DoubleScale\Pro\Modules\Integrations\Abstracts\Integration as Integration_Abstract;
use DoubleScale\Core\Managers\IntegrationsManager;

/**
 * ActiveCampaign class
 */
class Integration extends Integration_Abstract {

	/**
	 * Integration Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'ActiveCampaign';

	/**
	 * Integration Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'activecampaign';

	/**
	 * Integration Description
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $description = 'ActiveCampaign Integration';

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

		$api_url = $this->get_setting( 'api_url' );
		$api_key = $this->get_setting( 'api_key' );

		if ( empty( $api_url ) || empty( $api_key ) ) {
			return false;
		}

		$this->api = new Api( $api_url, $api_key );

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
		$api_url = $settings['api_url'] ?? '';
		$api_key = $settings['api_key'] ?? '';

		if ( empty( $api_url ) ) {
			return new \WP_Error( 'invalid_settings', __( 'Api URL is required.', 'doublescale') );
		}

		if ( empty( $api_key ) ) {
			return new \WP_Error( 'invalid_settings', __( 'Api Key is required.', 'doublescale') );
		}

		$this->endpoint = $api_url;
		$this->api_key  = $api_key;

		$api    = new Api( $api_url, $api_key );
		$result = $api->get( 'users/me' );
		if ( $result['success'] ) {
			return true;
		} else {
			return false;
		}
	}
}

IntegrationsManager::instance()->register( new Integration() );
