<?php
/**
 * Outlook Mailer.
 *
 * @since 1.0.0
 *
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\Outlook;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Smtp\Mailer\Provider\Provider;
use DoubleScale\Modules\Smtp\Mailer\Settings;

/**
 * Outlook Mailer Class.
 *
 * @since 1.0.0
 */
class Outlook extends Provider {

	/**
	 * Mailer slug.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $slug = 'outlook';

	/**
	 * Mailer name.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $name = 'Outlook';

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
		'rest'     => REST\REST::class,
		'accounts' => Accounts::class,
		'settings' => Settings::class,
		'process'  => Process::class,
	);

	/**
	 * Initialize
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function init() {
		parent::init();

		$this->app = new App( $this );
	}
}
