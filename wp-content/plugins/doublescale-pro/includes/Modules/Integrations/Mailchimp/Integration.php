<?php
/**
 * Class Mailchimp
 *
 * This class is responsible for handling the Mailchimp integration
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Mailchimp;

use DoubleScale\Pro\Modules\Integrations\Abstracts\Integration as Integration_Abstract;
use DoubleScale\Core\Managers\IntegrationsManager;

/**
 * Mailchimp class
 */
class Integration extends Integration_Abstract {

	/**
	 * Integration Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Mailchimp';

	/**
	 * Integration Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'mailchimp';

	/**
	 * Integration Description
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $description = 'Mailchimp Integration';

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

		if ( empty( $api_key ) ) {
			return false;
		}

		$this->api = new Api( $api_key );

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

		if ( empty( $api_key ) ) {
			return new \WP_Error( 'invalid_settings', __( 'Api Key is required.', 'doublescale') );
		}

		$api    = new Api( $api_key );
		$result = $api->get_lists();
		if ( $result['success'] ) {
			$list_id = $result['data']['lists'][0]['id'];
			$this->update_setting( 'list_id', $list_id );
			return true;
		} else {
			return false;
		}
	}
}

IntegrationsManager::instance()->register( new Integration() );
