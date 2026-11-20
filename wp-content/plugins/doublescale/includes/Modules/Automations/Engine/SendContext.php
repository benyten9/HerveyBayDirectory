<?php
/**
 * What kind of run a workflow send step is in.
 *
 * Send steps (email, SMS, WhatsApp) may hand their message to the send queue
 * instead of sending inline. They queue during bulk runs (a list or tag
 * applied to many contacts, an import) and never during a test run.
 *
 * @package DoubleScale\Modules\Automations\Engine
 */

namespace DoubleScale\Modules\Automations\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * SendContext flags.
 */
final class SendContext {

	/** @var int Nested bulk scopes. */
	private static $bulk = 0;

	/** @var bool The step running now belongs to a test run. */
	private static $test = false;

	/**
	 * Run $callback as part of a bulk run.
	 *
	 * @param callable $callback Work.
	 * @return mixed
	 */
	public static function bulk( callable $callback ) {
		++self::$bulk;
		try {
			return $callback();
		} finally {
			--self::$bulk;
		}
	}

	/**
	 * @return bool
	 */
	public static function is_bulk(): bool {
		return self::$bulk > 0;
	}

	/**
	 * Mark whether the running step is part of a test run.
	 *
	 * @param bool $test Test run.
	 * @return bool Previous value, to restore afterwards.
	 */
	public static function set_test( bool $test ): bool {
		$previous   = self::$test;
		self::$test = $test;
		return $previous;
	}

	/**
	 * @return bool
	 */
	public static function is_test(): bool {
		return self::$test;
	}
}
