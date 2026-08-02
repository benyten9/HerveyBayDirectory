<?php
/**
 * REST class.
 *
 * @since 1.0.0
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\Outlook\REST;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Smtp\Mailer\Provider\REST\REST as Abstract_REST;

/**
 * REST class.
 *
 * @since 1.0.0
 */
class REST extends Abstract_REST {

	/**
	 * Class names
	 *
	 * @var array
	 */
	protected static $classes = array(
		'settings_controller' => Settings_Controller::class,
		'account_controller'  => Account_Controller::class,
	);

	/**
	 * Get rest data
	 *
	 * @since 1.0.0
	 *
	 * @param Settings $settings Settings.
	 * @return mixed
	 */
	protected function get_rest_data( $settings ) {
		$rest_data = parent::get_rest_data( $settings );

		$app              = $this->mailer->settings->get( 'app' ) ?? array();
		$rest_data['app'] = $app;
		return $rest_data;
	}
}
