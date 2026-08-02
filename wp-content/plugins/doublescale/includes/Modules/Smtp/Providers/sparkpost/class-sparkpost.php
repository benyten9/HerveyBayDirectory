<?php
/**
 * SparkPost Mailer.
 *
 * @since 1.0.0
 *
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\SparkPost;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Smtp\Mailer\Provider\Provider;
use DoubleScale\Modules\Smtp\Mailer\Settings;

/**
 * SparkPost Mailer Class.
 *
 * @since 1.0.0
 */
class SparkPost extends Provider {

	/**
	 * Mailer slug.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $slug = 'sparkpost';

	/**
	 * Mailer name.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $name = 'SparkPost';

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
