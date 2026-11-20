<?php
/**
 * How the site writes dates (Settings → General), for the browser.
 *
 * Every screen and page that shows dates in JavaScript formats them with
 * these (src/shared/lib/site-date.ts), never the browser's own order: a UK
 * site set to d/m/Y must not show 11/9/2026 for 9 November.
 *
 * @package DoubleScale\Core\Settings
 */

namespace DoubleScale\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * SiteDates.
 */
final class SiteDates {

	/**
	 * The site's Date Format, Time Format, Week Starts On and language.
	 *
	 * @return array{date: string, time: string, weekStart: int, locale: string}
	 */
	public static function config(): array {
		return array(
			'date'      => (string) get_option( 'date_format', 'F j, Y' ),
			'time'      => (string) get_option( 'time_format', 'g:i a' ),
			'weekStart' => (int) get_option( 'start_of_week', 1 ),
			'locale'    => str_replace( '_', '-', determine_locale() ),
		);
	}
}
