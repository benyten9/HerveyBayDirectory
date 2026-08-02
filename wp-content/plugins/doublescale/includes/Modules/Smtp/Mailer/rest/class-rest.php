<?php
/**
 * REST class.
 *
 * @since 1.0.0
 * @package smtp
 */

namespace DoubleScale\Modules\Smtp\Mailer\REST;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Smtp\Mailer\Mailer;

/**
 * REST class.
 *
 * @since 1.0.0
 */
class REST {

	/**
	 * Mailer
	 *
	 * @var Mailer
	 */
	protected $mailer;

	/**
	 * Class names
	 *
	 * @var array
	 */
	protected static $classes = array(
		// 'settings_controller'   => Settings_Controller::class,
	);

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Mailer $mailer Mailer.
	 */
	public function __construct( $mailer ) {
		$this->mailer = $mailer;
		if ( ! empty( static::$classes['settings_controller'] ) ) {
			new static::$classes['settings_controller']( $this->mailer );
		}
	}
}
