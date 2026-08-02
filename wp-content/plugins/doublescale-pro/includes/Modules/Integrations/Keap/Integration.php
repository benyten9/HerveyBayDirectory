<?php
/**
 * Class Keap
 *
 * This class is responsible for handling the Keap integration
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Keap;

use DoubleScale\Pro\Modules\Integrations\Abstracts\Integration as Integration_Abstract;
use DoubleScale\Core\Managers\IntegrationsManager;

/**
 * Keap class
 */
class Integration extends Integration_Abstract {

	/**
	 * Integration Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Keap';

	/**
	 * Integration Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'keap';

	/**
	 * Integration Description
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $description = 'Keap Integration';

	/**
	 * App
	 *
	 * @var App
	 */
	public $app;

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
	 * Constructor
	 */
	public function __construct() {
		parent::__construct();

		$this->app = new App( $this );
	}

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

		$tokens        = $this->get_setting( 'credentials', array() );
		$access_token  = $tokens['access_token'] ?? '';
		$refresh_token = $tokens['refresh_token'] ?? '';
		if ( empty( $access_token ) || empty( $refresh_token ) ) {
			return false;
		}

		$this->api = new Api( $access_token, $refresh_token, $this->app );

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
		$client_id = $settings['client_id'] ?? '';
		$secret    = $settings['client_secret'] ?? '';

		if ( empty( $client_id ) ) {
			return new \WP_Error( 'invalid_settings', __( 'Api Key is required.', 'doublescale') );
		}

		if ( empty( $secret ) ) {
			return new \WP_Error( 'invalid_settings', __( 'Api Secret is required.', 'doublescale') );
		}

		return true;
	}
}

IntegrationsManager::instance()->register( new Integration() );
