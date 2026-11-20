<?php
/**
 * Mailer for toSend.
 *
 * @since 1.4.10
 *
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\ToSend;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Smtp\Mailer\Provider\Provider;
use DoubleScale\Modules\Smtp\Mailer\Settings;

/**
 * Mailer class for toSend.
 *
 * @since 1.4.10
 */
class ToSend extends Provider {

	/**
	 * Mailer slug.
	 *
	 * @var string
	 */
	public $slug = 'tosend';

	/**
	 * Mailer name.
	 *
	 * @var string
	 */
	public $name = 'toSend';

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
