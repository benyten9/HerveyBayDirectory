<?php
/**
 * Removes the .ics files the booking confirmation page used to leave behind.
 *
 * Until 2026-10-11 every view of a confirmation page wrote
 * `uploads/<year>/<month>/doublescale_booking_<uniqid>.ics` and never used or
 * removed it. Those are public URLs carrying the event name and location, and
 * they pile up by the hundred. The page no longer writes them; this deletes
 * the ones already there, once per site, in the background.
 *
 * Only files with that exact name AND the signature the page wrote
 * (`PRODID:-//DoubleScale//EN`) are touched. The .ics attached to booking
 * emails is a temp file named `doublescale-booking-<id>-…`, never in uploads.
 *
 * Runs whether or not the Booking module is on: the files outlive it.
 *
 * @package DoubleScale\Modules\Booking
 */

namespace DoubleScale\Modules\Booking\Services;

defined( 'ABSPATH' ) || exit;

/**
 * ConfirmPageIcsCleanup class.
 */
final class ConfirmPageIcsCleanup {

	/** WP-Cron hook for one batch. */
	const HOOK = 'doublescale_booking_cleanup_confirm_page_ics';

	/** Set once nothing is left to delete; the cleanup never runs again. */
	const DONE_OPTION = 'doublescale_booking_confirm_ics_cleaned';

	/** Files deleted per batch. */
	const BATCH = 200;

	/** The name the confirmation page gave its files (uniqid() is 13 hex chars). */
	const NAME_PATTERN = '/^doublescale_booking_[0-9a-f]{13}\.ics$/';

	/** What the confirmation page wrote into every file. */
	const SIGNATURE = 'PRODID:-//DoubleScale//EN';

	/**
	 * Hook the batch runner and schedule the first batch until the site is clean.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );

		if ( get_option( self::DONE_OPTION ) || wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
	}

	/**
	 * Delete one batch. Schedules the next while more remain, otherwise marks
	 * the site clean.
	 *
	 * @return int Files deleted.
	 */
	public static function run(): int {
		/**
		 * Files deleted per batch.
		 *
		 * @param int $batch Batch size.
		 */
		$batch   = max( 1, (int) apply_filters( 'doublescale_booking_confirm_ics_cleanup_batch', self::BATCH ) );
		$found   = self::find();
		$deleted = 0;

		foreach ( array_slice( $found, 0, $batch ) as $path ) {
			wp_delete_file( $path );
			if ( ! file_exists( $path ) ) {
				++$deleted;
			}
		}

		if ( count( $found ) > $batch ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		} else {
			update_option( self::DONE_OPTION, gmdate( 'Y-m-d H:i:s' ) );
		}

		return $deleted;
	}

	/**
	 * Confirmation-page files in uploads: the dated folders and, for sites that
	 * do not organise uploads by month, the uploads root.
	 *
	 * @return array<int, string>
	 */
	private static function find(): array {
		$basedir = wp_get_upload_dir()['basedir'] ?? '';
		if ( '' === $basedir || ! is_dir( $basedir ) ) {
			return array();
		}

		$candidates = array_merge(
			(array) glob( trailingslashit( $basedir ) . 'doublescale_booking_*.ics', GLOB_NOSORT ),
			(array) glob( trailingslashit( $basedir ) . '[0-9][0-9][0-9][0-9]/[0-9][0-9]/doublescale_booking_*.ics', GLOB_NOSORT )
		);

		return array_values(
			array_filter(
				array_filter( $candidates ),
				static function ( $path ) {
					return 1 === preg_match( self::NAME_PATTERN, basename( (string) $path ) )
						&& self::has_signature( (string) $path );
				}
			)
		);
	}

	/**
	 * Whether a file is a calendar written by the confirmation page.
	 *
	 * @param string $path File.
	 * @return bool
	 */
	private static function has_signature( string $path ): bool {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, first bytes only.
		$head = (string) file_get_contents( $path, false, null, 0, 256 );

		return 0 === strpos( $head, 'BEGIN:VCALENDAR' ) && false !== strpos( $head, self::SIGNATURE );
	}
}
