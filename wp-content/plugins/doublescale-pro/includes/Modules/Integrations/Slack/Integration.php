<?php
/**
 * Class Slack
 *
 * This class is responsible for handling the Slack integration
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Slack;

use DoubleScale\Pro\Modules\Integrations\Abstracts\Integration as Integration_Abstract;
use DoubleScale\Core\Managers\IntegrationsManager;

/**
 * Slack class
 */
class Integration extends Integration_Abstract {

	/**
	 * Integration Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Slack';

	/**
	 * Integration Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'slack';

	/**
	 * Integration Description
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $description = 'Slack is a digital headquarters that brings all your work communication and tools together in one place, like a shared workspace for your team.';

	/**
	 * Is Pro feature
	 *
	 * @var bool
	 *
	 * @since 1.0.0
	 */
	public $is_pro = false;

	/**
	 * Option name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $option_name = 'slack';

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

		if ( empty( $access_token ) ) {
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
	 * @return bool|\WP_Error
	 */
	public function validate( $settings ) {
		$app_credentials = $settings['app'] ?? array();
		$client_id       = $app_credentials['client_id'] ?? '';
		$client_secret   = $app_credentials['client_secret'] ?? '';

		if ( empty( $client_id ) ) {
			return new \WP_Error( 'invalid_settings', __( 'Client ID is required.', 'doublescale') );
		}

		if ( empty( $client_secret ) ) {
			return new \WP_Error( 'invalid_settings', __( 'Client Secret is required.', 'doublescale') );
		}

		// For OAuth integrations, we can't fully validate without user authorization
		// So we just validate that credentials are present
		return true;
	}
}

// Registration moved to Plugin main class
