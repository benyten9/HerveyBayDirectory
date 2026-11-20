<?php
/**
 * REST class.
 *
 * @since 1.4.10
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\ToSend\REST;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Smtp\Mailer\Provider\REST\REST as Abstract_REST;

/**
 * REST class.
 *
 * @since 1.4.10
 */
class REST extends Abstract_REST {

	/**
	 * Class names
	 *
	 * @var array
	 */
	protected static $classes = array(
		'account_controller' => Account_Controller::class,
	);
}
