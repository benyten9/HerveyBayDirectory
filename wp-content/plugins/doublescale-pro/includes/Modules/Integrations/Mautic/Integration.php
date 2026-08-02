<?php
/**
 * Class Mautic
 *
 * This class is responsible for handling the Mautic integration
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Mautic;

use DoubleScale\Pro\Modules\Integrations\Abstracts\Integration as Integration_Abstract;
use DoubleScale\Core\Managers\IntegrationsManager;

/**
 * Mautic class
 */
class Integration extends Integration_Abstract {

	/**
	 * Integration Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Mautic';

	/**
	 * Integration Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'mautic';

	/**
	 * Integration Description
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $description = 'Mautic Integration';

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
		$app           = $this->get_setting( 'app', array() );
		$base_url      = $app['base_url'] ?? '';

		if ( empty( $access_token ) || empty( $refresh_token ) || empty( $base_url ) ) {
			return false;
		}

		$this->api = new Api( $access_token, $refresh_token, $base_url, $this->app );

		return $this->api;
	}

}

IntegrationsManager::instance()->register( new Integration() );
