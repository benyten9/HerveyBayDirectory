<?php
/**
 * Emailit Mailer.
 *
 * @since 1.4.10
 *
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\Emailit;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Smtp\Mailer\Provider\Provider;
use DoubleScale\Modules\Smtp\Mailer\Settings;

/**
 * Emailit Mailer Class.
 *
 * @since 1.4.10
 */
class Emailit extends Provider {

	/**
	 * Mailer slug.
	 *
	 * @var string
	 */
	public $slug = 'emailit';

	/**
	 * Mailer name.
	 *
	 * @var string
	 */
	public $name = 'Emailit';

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
}
