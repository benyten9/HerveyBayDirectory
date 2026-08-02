<?php

namespace SEOPressPro\Actions\Admin;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

use SEOPress\Core\Hooks\ExecuteHooks;

/**
 * Snapshots the custom robots.txt content into its revisions history whenever
 * the SEOPress PRO robots settings are saved with a changed file.
 *
 * Mirrors the option-detection logic of PurgeRobotsCache: it listens to the
 * generic `updated_option` hook and only acts when the robots.txt content
 * actually changes.
 *
 * @since 10.0.0
 */
class RecordRobotsRevision implements ExecuteHooks {

	/**
	 * @return void
	 */
	public function hooks() {
		add_action( 'updated_option', array( $this, 'maybeRecord' ), 10, 3 );
	}

	/**
	 * Record a revision when the per-site robots.txt content changed.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Previous value.
	 * @param mixed  $new_value New value.
	 *
	 * @return void
	 */
	public function maybeRecord( $option, $old_value, $new_value ) {
		if ( 'seopress_pro_option_name' !== $option ) {
			return;
		}

		$service = function_exists( 'seopress_pro_get_service' ) ? seopress_pro_get_service( 'RobotsTxtRevisions' ) : null;
		if ( null === $service ) {
			return;
		}

		$old_file = is_array( $old_value ) && isset( $old_value['seopress_robots_file'] ) ? $old_value['seopress_robots_file'] : '';
		$new_file = is_array( $new_value ) && isset( $new_value['seopress_robots_file'] ) ? $new_value['seopress_robots_file'] : '';

		if ( $old_file === $new_file ) {
			return;
		}

		// Seed the history with the state that existed before versioning, so the
		// user can roll back to it. record() skips it if it is already stored.
		if ( '' !== (string) $old_file ) {
			$service->record( $old_file, $service::TYPE_SITE );
		}

		$service->record( $new_file, $service::TYPE_SITE );
	}
}
