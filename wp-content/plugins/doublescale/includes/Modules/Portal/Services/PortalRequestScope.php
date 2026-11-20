<?php
/**
 * What the portal page asking for data is limited to.
 *
 * A portal placed with the shortcode's `box_id` shows one support department.
 * Its tickets list asks for that department; the sidebar badge and the Home
 * "Open tickets" card are counted while the bootstrap request runs, so they
 * read the department from here and agree with the list.
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Services;

defined( 'ABSPATH' ) || exit;

final class PortalRequestScope {

	/** @var int Support department (mailbox) the page is locked to; 0 = all. */
	private static $mailbox_id = 0;

	/**
	 * The support department the current portal request is locked to, or 0.
	 *
	 * @return int
	 */
	public static function mailbox_id(): int {
		return self::$mailbox_id;
	}

	/**
	 * Run `$callback` with the request locked to one support department.
	 *
	 * @param int      $mailbox_id Department id (0 = all).
	 * @param callable $callback   Work to run.
	 * @return mixed What the callback returns.
	 */
	public static function with_mailbox( int $mailbox_id, callable $callback ) {
		$previous         = self::$mailbox_id;
		self::$mailbox_id = max( 0, $mailbox_id );
		try {
			return $callback();
		} finally {
			self::$mailbox_id = $previous;
		}
	}
}
