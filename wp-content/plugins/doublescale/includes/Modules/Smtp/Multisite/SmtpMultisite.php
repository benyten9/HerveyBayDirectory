<?php
/**
 * Multisite SMTP bootstrap (reserved; each site uses its own SMTP option).
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Smtp\Multisite;

defined( 'ABSPATH' ) || exit;

/**
 * Placeholder retained for {@see Module} wiring.
 */
final class SmtpMultisite {

	/**
	 * Register hooks (none — subsites no longer inherit network SMTP from the main site).
	 */
	public static function boot(): void {
	}
}
