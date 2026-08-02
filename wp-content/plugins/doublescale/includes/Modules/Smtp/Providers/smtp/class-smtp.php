<?php
/**
 * SMTP Mailer.
 *
 * @since 1.0.0
 *
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\SMTP;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Smtp\Mailer\Provider\Provider;
use DoubleScale\Modules\Smtp\Mailer\Settings;

/**
 * SMTP Mailer Class.
 *
 * @since 1.0.0
 */
class SMTP extends Provider {

	/**
	 * Mailer slug.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $slug = 'smtp';

	/**
	 * Mailer name.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $name = 'Other SMTP';

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
